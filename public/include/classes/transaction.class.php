<?php

// Make sure we are called from index.php
if (!defined('SECURITY'))
  die('Hacking attempt');

class Transaction {
  private $sError = '';
  private $table = 'transactions';
  private $tableBlocks = 'blocks';

  public function __construct($debug, $mysqli, $config, $block, $user) {
    $this->debug = $debug;
    $this->mysqli = $mysqli;
    $this->config = $config;
    $this->block = $block;
    $this->user = $user;
    $this->debug->append("Instantiated Transaction class", 2);
  }

  // get and set methods
  private function setErrorMessage($msg) {
    $this->sError = $msg;
  }
  public function getError() {
    return $this->sError;
  }

  /**
   * Add a new transaction to our class table
   * @param account_id int Account ID to book transaction for
   * @param amount float Coin amount
   * @param type string Transaction type [Credit, Debit_AP, Debit_MP, Fee, Donation, Orphan_Credit, Orphan_Fee, Orphan_Donation]
   * @param block_id int Block ID to link transaction to [optional]
   * @param coin_address string Coin address for this transaction [optional]
   * @return bool
   **/
  public function addTransaction($account_id, $amount, $type='Credit', $block_id=NULL, $coin_address=NULL) {
    $stmt = $this->mysqli->prepare("INSERT INTO $this->table (account_id, amount, block_id, type, coin_address) VALUES (?, ?, ?, ?, ?)");
    if ($this->checkStmt($stmt)) {
      $stmt->bind_param("idiss", $account_id, $amount, $block_id, $type, $coin_address);
      if ($stmt->execute()) {
        $this->setErrorMessage("Failed to store transaction");
        $stmt->close();
        return true;
      }
    }
    return false;
  }

  /**
   * Sometimes transactions become orphans when a block associated to them is orphaned
   * Updates the transaction types to Orphan_<type>
   * @param block_id int Orphaned block ID
   * @return bool
   **/
  public function setOrphan($block_id) {
    $this->debug->append("STA " . __METHOD__, 4);
    $aOrphans = array(
      'Credit' => 'Orphan_Credit',
      'Fee' => 'Orphan_Fee',
      'Donation' => 'Orphan_Donation',
      'Bonus' => 'Orphan_Bonus'
    );
    foreach ($aOrphans as $from => $to) {
      $stmt = $this->mysqli->prepare("
        UPDATE $this->table
        SET type = '$to'
        WHERE type = '$from'
        AND block_id = ?
        ");
      if (!($this->checkStmt($stmt) && $stmt->bind_param('i', $block_id) && $stmt->execute())) {
        $this->debug->append("Failed to set orphan $from => $to transactions for $block_id");
        return false;
      }
    }
    return true;
  }

  /**
   * Get all transactions from start for account_id
   * @param account_id int Account ID
   * @param start int Starting point, id of transaction
   * @return data array Database fields as defined in SELECT
   **/
  public function getTransactions($account_id, $start=0) {
    $this->debug->append("STA " . __METHOD__, 4);
    $stmt = $this->mysqli->prepare("
      SELECT
        t.id AS id,
        t.type AS type,
        t.amount AS amount,
        t.coin_address AS coin_address,
        t.timestamp AS timestamp,
        b.height AS height,
        b.confirmations AS confirmations
      FROM transactions AS t
      LEFT JOIN " . $this->block->getTableName() . " AS b ON t.block_id = b.id
      WHERE t.account_id = ?
      ORDER BY id DESC");
    if ($this->checkStmt($stmt)) {
      if(!$stmt->bind_param('i', $account_id)) return false;
      $stmt->execute();
      $result = $stmt->get_result();
      return $result->fetch_all(MYSQLI_ASSOC);
    }
    $this->debug->append('Unable to fetch transactions');
    return false;
  }

  /**
   * Fetch all transactions for all users
   * @param none
   * @return mixed array or false
   **/
  public function getAllTransactions($start=0) {
    $this->debug->append("STA " . __METHOD__, 4);
    $stmt = $this->mysqli->prepare("
      SELECT
        t.id AS id,
        a.username as username,
        t.type AS type,
        t.amount AS amount,
        t.coin_address AS coin_address,
        t.timestamp AS timestamp,
        b.height AS height,
        b.confirmations AS confirmations
      FROM transactions AS t
      LEFT JOIN " . $this->block->getTableName() . " AS b ON t.block_id = b.id
      LEFT JOIN " . $this->user->getTableName() . " AS a ON t.account_id = a.id
      ORDER BY id DESC
      LIMIT ?,30");
    if ($this->checkStmt($stmt) && $stmt->bind_param('i', $start) && $stmt->execute() && $result = $stmt->get_result())
      return $result->fetch_all(MYSQLI_ASSOC);
    $this->debug->append('Unable to fetch transactions');
    return false;
  }

  private function checkStmt($bState) {
    if ($bState ===! true) {
      $this->debug->append("Failed to prepare statement: " . $this->mysqli->error);
      $this->setErrorMessage('Internal application Error');
      return false;
    }
    return true;
  }

  /**
   * Get all donation transactions
   * Used on donors page
   * return data array Donors and amounts
   **/
  public function getDonations() {
    $this->debug->append("STA " . __METHOD__, 4);
    $stmt = $this->mysqli->prepare("
      SELECT
        SUM(t.amount) AS donation,
        a.username AS username,
        a.donate_percent AS donate_percent
      FROM $this->table AS t
      LEFT JOIN " . $this->user->getTableName() . " AS a
      ON t.account_id = a.id
      LEFT JOIN blocks AS b
      ON t.block_id = b.id
      WHERE
      (
        ( t.type = 'Donation' AND b.confirmations >= " . $this->config['confirmations'] . " ) OR
        t.type = 'Donation_PPS'
      )
      GROUP BY a.username
      ");
    if ($this->checkStmt($stmt) && $stmt->execute() && $result = $stmt->get_result())
      return $result->fetch_all(MYSQLI_ASSOC);
    $this->debug->append("Failed to fetch website donors: " . $this->mysqli->error);
    return false;
  }

  /**
   * Get total balance for all users locked in wallet
   * This includes any outstanding unconfirmed transactions!
   * @param none
   * @return data double Amount locked for users
   **/
  public function getLockedBalance() {
    $this->debug->append("STA " . __METHOD__, 4);
    $stmt = $this->mysqli->prepare("
      SELECT ROUND(IFNULL(t1.credit, 0) - IFNULL(t2.debit, 0) - IFNULL(t3.other, 0), 8) AS balance
      FROM
      (
        SELECT sum(t.amount) AS credit
        FROM $this->table AS t
        LEFT JOIN " . $this->block->getTableName() . " AS b ON t.block_id = b.id
        WHERE (
          ( t.type IN ('Credit','Bonus') AND b.confirmations >= ? ) OR
          ( t.type = 'Credit_PPS' )
        )
      ) AS t1,
      (
        SELECT sum(t.amount) AS debit
        FROM $this->table AS t
        WHERE t.type IN ('Debit_MP', 'Debit_AP')
      ) AS t2,
      (
        SELECT sum(t.amount) AS other
        FROM " . $this->table . " AS t
        LEFT JOIN " . $this->block->getTableName() . " AS b ON t.block_id = b.id
        WHERE (
          ( t.type IN ('Donation','Fee') AND b.confirmations >= ? ) OR
          t.type IN ('Donation_PPS','Fee_PPS','TXFee')
        )
      ) AS t3");
    if ($this->checkStmt($stmt) && $stmt->bind_param('ii', $this->config['confirmations'], $this->config['confirmations']) && $stmt->execute() && $stmt->bind_result($dBalance) && $stmt->fetch())
      return $dBalance;
    // Catchall
    $this->setErrorMessage('Unable to find locked credits for all users');
    $this->debug->append('MySQL query failed : ' . $this->mysqli->error);
    return false;
  }

  /**
   * Get an accounts total balance
   * @param account_id int Account ID
   * @return data float Credit - Debit - Fees - Donation
   **/
  public function getBalance($account_id) {
    $this->debug->append("STA " . __METHOD__, 4);
    $stmt = $this->mysqli->prepare("
      SELECT
        ROUND(IFNULL(t1.credit, 0) - IFNULL(t2.debit, 0) - IFNULL(t3.other, 0), 8) AS confirmed,
        ROUND(IFNULL(t4.credit, 0) - IFNULL(t5.other, 0), 8) AS unconfirmed
      FROM
      (
        SELECT sum(t.amount) AS credit
        FROM $this->table AS t
        LEFT JOIN " . $this->block->getTableName() . " AS b ON t.block_id = b.id
        WHERE
        (
          ( t.type IN ('Credit','Bonus') AND b.confirmations >= ? ) OR
          ( t.type = 'Credit_PPS' )
        )
        AND t.account_id = ?
      ) AS t1,
      (
        SELECT sum(t.amount) AS debit
        FROM $this->table AS t
        WHERE t.type IN ('Debit_MP', 'Debit_AP')
        AND t.account_id = ?
      ) AS t2,
      (
        SELECT sum(t.amount) AS other
        FROM $this->table AS t
        LEFT JOIN " . $this->block->getTableName() . " AS b ON t.block_id = b.id
        WHERE
        (
          ( t.type IN ('Donation','Fee') AND b.confirmations >= ? ) OR
          ( t.type IN ('Donation_PPS', 'Fee_PPS', 'TXFee') )
        )
        AND t.account_id = ?
      ) AS t3,
      (
        SELECT sum(t.amount) AS credit
        FROM $this->table AS t
        LEFT JOIN " . $this->block->getTableName() . " AS b ON t.block_id = b.id
        WHERE
          t.type IN ('Credit','Bonus') AND b.confirmations < ?
        AND t.account_id = ?
      ) AS t4,
      (
        SELECT sum(t.amount) AS other
        FROM $this->table AS t
        LEFT JOIN " . $this->block->getTableName() . " AS b ON t.block_id = b.id
        WHERE
        (
          t.type IN ('Donation','Fee') AND b.confirmations < ?
        )
        AND t.account_id = ?
      ) AS t5
      ");
    if ($this->checkStmt($stmt)) {
      $stmt->bind_param("iiiiiiiii", $this->config['confirmations'], $account_id, $account_id, $this->config['confirmations'], $account_id, $this->config['confirmations'], $account_id, $this->config['confirmations'], $account_id);
      if (!$stmt->execute()) {
        $this->debug->append("Unable to execute statement: " . $stmt->error);
        $this->setErrorMessage("Fetching balance failed");
      }
      $result = $stmt->get_result();
      $stmt->close();
      return $result->fetch_assoc();
    }
    return false;
  }
}

$transaction = new Transaction($debug, $mysqli, $config, $block, $user);

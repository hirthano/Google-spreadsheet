<?php
dl('igbinary.so');
dl('couchbase.so');
class Couchbase {
  private $conn = null;
  private $host = null;
  private $buck = null;

  public function __construct($config) {
    $this->host = $config['chost'];
    $this->buck = $config['cbuck'];
    // echo "New couchbase " . date('Y-m-d H:i:s'). "\n";
    $cluster = new \CouchbaseCluster($this->host);
    // echo "Open bucket " . date('Y-m-d H:i:s'). "\n";
    $this->conn = $cluster->openBucket($this->buck, $config['secret']);
    // echo "done ". date('Y-m-d H:i:s') . "\n";
  }

  public function insert($id, $param = array()) {
    try {
      if(strlen($id) <= 0) return false;
      $result = $this->conn->insert($id, $param);
      return ($result->error === null) ? true : false;
    } catch(Exception $ex) {
      return false;
    }
  }

  public function replace($id, $param = array()) {
    try {
      if(strlen($id) <= 0) return false;
      $result = $this->conn->upsert($id, $param);
      return ($result->error === null) ? true : false;
    } catch(Exception $ex) {
      return false;
    }
  }

  public function update($id, $param = array()) {
    try {
      if(strlen($id) <= 0) return false;
      $result = $this->conn->replace($id, $param);
      return ($result->error === null) ? true : false;
    } catch(Exception $ex) {
      return false;
    }
  }

  public function delete($id) {
    try {
      if(strlen($id) <= 0) return false;
      $result = $this->conn->remove($id);
      return ($result->error === null) ? true : false;
    } catch(Exception $ex) {
      return false;
    }
  }

  public function getOne($id = '') {
    if (strlen($id) == 0) return false;
    $result = $this->conn->get($id);

    try {
      if (count($result) > 0) {
        $r = $result->value;
        if(is_string($r))
          $r = json_decode($r);
        return get_object_vars($r);
      } else return false;
    } catch (Exception $ex) {
      return false;
    }
  }

  public function query($query = '') {
    try {
      if (strlen($query) <= 0) return array();
      $query = CouchbaseN1qlQuery::fromString($query);
      $query->consistency(\Couchbase\N1qlQuery::NOT_BOUNDED);
      $query->crossBucket(true);
      return $this->conn->query($query,true);
    } catch (Exception $ex) {
      return null;
    }
  }

  public function viewQuery($document_name,$view_name,$startkey =array(), $endkey =array(), $group_level = '') {
    // range(mixed  $start = NULL, mixed  $end = NULL, boolean  $inclusive_end = false) : $this
    // group_level(  $group_level)
    try {
      if (strlen($document_name) <= 0 || strlen($view_name) <= 0) return array();
      $query = CouchbaseViewQuery::from($document_name, $view_name);
      if(count($startkey) > 0 || count($endkey) > 0)
        $query->range($startkey,$endkey);
      if(strlen($group_level) > 0)
        $query->group_level($group_level);
      $result = $this->conn->query($query,true);
      if(isset($result)){
        $result=$result->rows;
        $summary = array();
        if(isset($result[0])){
          foreach($result as $row){
            $summary[]=json_decode(json_encode($row),true);
          }
          return $summary;
        } else {
          return array();
        }
      }
      return $this->conn->query($query,true);
    } catch (Exception $ex) {
      return null;
    }
  }

  public function select($query = '') {
    try {
      $result = self::query($query);
      if(isset($result)){
        $result=$result->rows;
        $summary = array();
        if(isset($result[0][$this->buck])){
          foreach($result as $row)
            $summary[]=$row[$this->buck];
          return $summary;
        } else {
          return $result;
        }
      }
    } catch (Exception $ex) {
      return null;
    }
  }
}
?>

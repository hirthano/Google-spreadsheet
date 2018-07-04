<?php
dl('solr.so');
class Solr{
    private $config = null;

    public function __construct($config){
      $this->config = array(
        'hostname'  => $config['endpoint']['localhost']['host'],
        'port'      => $config['endpoint']['localhost']['port'],
        'path'      => $config['endpoint']['localhost']['path'],
        'timeout'   => "300",
      );
        // $this->config = $config;
    }

    public static function createDocument($doc){
        $solr_doc = new SolrInputDocument();
        foreach($doc as $name => $value){
            if(is_array($value)){
                foreach($value as $item){
                    if(is_array($item))
                      $item = json_encode($item);
                    $solr_doc->addField($name, $item);
                }
            } else {
                $solr_doc->addField($name, $value);
            }
        }
        return $solr_doc;
    }

    public function solr_replace($docs){
        $client = new SolrClient($this->config);
        $solr_docs = [];
        foreach($docs as $doc){
            $solr_docs[] = self::createDocument($doc);
        }
        $client->addDocuments($solr_docs, true);
        $client->commit();
    }

    public function optimize(){
        $client = new SolrClient($this->config);
        $client->optimize();
    }

    public function solr_delete($ids){
        $client = new SolrClient($this->config);

        foreach($ids as $id)
          $client->deleteById($id);
        $client->commit();
    }

    public function solr_update($id, $new_field = array()){
      $client = new SolrClient($this->config);
      if (count($new_field) == 0)
        return;
      else {
        $result = get_object_vars($client->getById($id)->getResponse()['doc']);
        foreach ($new_field as $id_row => $row){
          if(!isset($result[$id_row])){
            echo "Error, no field ".$id_row." in the document";
            return;
          }
          $result[$id_row] = $row;
        }
        self::solr_replace(array($result));
      }
    }

    public function getById($id){
        $client = new SolrClient($this->config);
        return $client->getById($id)->getResponse();
    }

    public function query($q, $opts = []){
        $client = new SolrClient($this->config);
        $query = new SolrQuery();
        foreach($opts as $name => $value){
            $query->setParam($name, $value);
        }

        $query->setQuery($q);
        return $client->query($query)->getResponse()["response"];
    }
}

//
// class Solr{
//     private $config = null;
//     private $client;
//
//     public function __construct($config){
//         $this->config = $config;
//         $this->client = new Solarium\Client($this->config);
//     }
//     // solr_select('shop_id, total_price','shop_id:2','total_price desc','1000');
//     public function solr_select($select = FALSE, $where = FALSE, $order_by = FALSE, $limit = FALSE){
//         $query = $this->client->createSelect();
//         if ($select !== FALSE){
//           $query->setFields($select);
//         }
//         if ($where !== FALSE)
//           $query->createFilterQuery('where')->setQuery($where);
//         if ($order_by !== FALSE){
//           $temp = preg_split('/\s+/', $order_by);
//           if(strtolower($temp[1]) == 'asc')
//             $query->addSort($temp[0], $query::SORT_ASC);
//           else
//             $query->addSort($temp[0], $query::SORT_DESC);
//         }
//         if ($limit !== FALSE){
//           $query->setRows($limit);
//         }
//
//         $resultset = $this->client->select($query)->getData();
//         return $resultset['response']['docs'];
//     }
//
//     public function solr_replace($docs){
//       if(!is_array($docs))
//         return "Document is not an array";
//       if(count($docs) == 0)
//         return;
//       $update = $this->client->createUpdate();
//       $solr_docs = array();
//       foreach($docs as $doc){
//         $solr_docs[] = $update->createDocument($doc);
//       }
//       print_r($solr_docs);
//       $update->addDocuments($solr_docs);
//       $update->addCommit();
//       $result = $this->client->update($update);
//
//       return $result;
//     }
//
//     public function solr_delete($ids){
//       if(!is_array($ids))
//         return "ID list is not an array";
//       if(count($ids) == 0)
//         return;
//       $update = $this->client->createUpdate();
//
//       foreach ($ids as $row)
//         $update->addDeleteQuery("id:$row");
//       $update->addCommit();
//       $result = $this->client->update($update);
//
//       return $result;
//     }
//
// }

<?php
chdir(__DIR__);
require_once("google_spreadsheet.php");
require_once("./class/db.php");
require_once('./class/miner_helper.php');

define('SHEET_ID', '15If60De4flYSc8kESYdEOUjJBYtqswcOycKva1Nwd10');
define('APPLICATION_NAME', 'Customer Info');
define('CLIENT_SECRET_PATH', './Credentials/credentials.json');
define('CREDENTIALS_PATH', './Credentials/api.json');
define('SCOPES', implode(' ', [Google_Service_Sheets::SPREADSHEETS]));

$local_sql_config = array(
  'host'	=> 'localhost',
  'name'	=> 'powersell_ui',
  'user'	=> 'hoatrinh',
  'pass'	=> 'hoatrinh@dmx@'
);

new_db($local_sql,$local_sql_config);
$client = getClient();
$service = new Google_Service_Sheets($client);

$data = $local_sql->my_select("
SELECT
    ifnull(user_id,'') user_id,
    ifnull(customer_name,'') customer_name,
    ifnull(account_user_email,'') account_user_email,
    ifnull(phone,'') phone,
    ifnull(account_created_at,'') account_created_at,
    ifnull(shop_name_by_customer,'') shop_name_by_customer,
    ifnull(shop_name_back_end,'') shop_name_back_end,
    ifnull(package_start,'') package_start,
    ifnull(package_end,'') package_end,
    ifnull(package_name,'') package_name,
    ifnull(shop_created_at,'') shop_created_at
FROM
    powersell_ui.get_user_data
WHERE
    account_created_at >= NOW() - INTERVAL 7 DAY
GROUP BY 1 , 3 , 4 , 5;
");

foreach ($data as $key => $value) {
  $data[$key] = array_values($data[$key]);
}

ClearValue("'Sheet1'!A3:M300");
echo date('Y-m-d H:i:s')."\n";
UpdateValue("'Sheet1'!B1",array(array(date('Y-m-d H:i:s'))));
UpdateValue("'Sheet1'!A3",$data);


?>

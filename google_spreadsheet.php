<?php
chdir(__DIR__);
require_once("./vendor/autoload.php");

function getClient()
{
    $client = new Google_Client();
    $client->setApplicationName(APPLICATION_NAME);
    $client->setScopes(SCOPES);
    $client->setAuthConfig(CLIENT_SECRET_PATH);
    $client->setAccessType('offline');

    // Load previously authorized credentials from a file.
    $credentialsPath = expandHomeDirectory(CREDENTIALS_PATH);
    if (file_exists($credentialsPath)) {
        $accessToken = json_decode(file_get_contents($credentialsPath), true);
    } else {
        // Request authorization from the user.
        $authUrl = $client->createAuthUrl();
        printf("Open the following link in your browser:\n%s\n", $authUrl);
        print 'Enter verification code: ';
        $authCode = trim(fgets(STDIN));

        // Exchange authorization code for an access token.
        $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);

        // Store the credentials to disk.
        if (!file_exists(dirname($credentialsPath))) {
            mkdir(dirname($credentialsPath), 0700, true);
        }
        file_put_contents('./Credentials/api.json', json_encode($accessToken));
        printf("Credentials saved to %s\n", $credentialsPath);
    }
    $client->setAccessToken($accessToken);

    // Refresh the token if it's expired.
    if ($client->isAccessTokenExpired()) {
        $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
        file_put_contents($credentialsPath, json_encode($client->getAccessToken()));
    }
    return $client;
}

function expandHomeDirectory($path)
{
  $homeDirectory = getenv('HOME');
  if (empty($homeDirectory)) {
    $homeDirectory = getenv('HOMEDRIVE') . getenv('HOMEPATH');
  }
  return str_replace('~', realpath($homeDirectory), $path);
}

function ClearValue($spreadsheet_area)
{
  global $service;
  global $requestBody;

  if (!isset($requestBody))
    $requestBody = new Google_Service_Sheets_ClearValuesRequest();

  $service->spreadsheets_values->clear(SHEET_ID, $spreadsheet_area, $requestBody);
}

function UpdateValue($spreadsheet_area, $data)
{
  global $service;

  $options = array('valueInputOption' => 'RAW');
  $new_value = new Google_Service_Sheets_ValueRange(['values' => $data]);
  $service->spreadsheets_values->update(SHEET_ID, $spreadsheet_area, $new_value, $options);
}





?>

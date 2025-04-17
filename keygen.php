<?php
require_once 'src/Sts.php';
require_once 'src/Scope.php';
require 'global_variables.php';

use QCloud\COSSTS\Sts;

function generateCosKey($ext) {
  $ymd = date('Ymd');
  $r = substr('000000' . rand(), -6);
  $cosKey = 'file/' . $ymd. '/' . $ymd . '_' . $r;
  if ($ext) {
    $cosKey = $cosKey . '.' . $ext;
  }
  return $cosKey;
};

function getKeyAndCredentials($filename) {
    global $cos_bucket,$cos_region,$cos_secret_id,$cos_secret_key;
  $permission = array(
    'limitExt' => true,
    'extWhiteList' => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff'], 
    'limitContentType' => true,
    'limitContentLength' => true,
  );
  $condition = array();

  $ext = pathinfo($filename, PATHINFO_EXTENSION);

  if ($permission['limitExt']) {
    if ($ext === '' || array_key_exists($ext, $permission['extWhiteList'])) {
      return '非法文件，禁止上传';
    }
  }

  if ($permission['limitContentType']) {
    $condition['string_like'] = array('cos:content-type' => 'image/*');
  }
  if ($permission['limitContentLength']) {
    $condition['numeric_less_than_equal'] = array('cos:content-length' => 10 * 1024 * 1024);
  }

  $bucket = $cos_bucket; 
  $region = $cos_region; 
  $allowedPrefixes=generateCosKey($ext);;
  $config = array(
    'url' => 'https://sts.tencentcloudapi.com/', 
    'domain' => 'sts.tencentcloudapi.com', 
    'proxy' => '',
    'secretId' => $cos_secret_id, 
    'secretKey' => $cos_secret_key, 
    'bucket' => $bucket, 
    'region' => $region, 
    'durationSeconds' => 180,
    'allowPrefix' => array($allowedPrefixes),
    'allowActions' => array (
        'name/cos:PutObject',
        'name/cos:InitiateMultipartUpload',
        'name/cos:ListMultipartUploads',
        'name/cos:ListParts',
        'name/cos:UploadPart',
        'name/cos:CompleteMultipartUpload'
    ),
  );

  if (!empty($condition)) {
    $config['condition'] = $condition;
  }

  $sts = new Sts();
  $tempKeys = $sts->getTempKeys($config);
  $resTemp = array(
    'TmpSecretId' => $tempKeys['credentials']['tmpSecretId'],
    'TmpSecretKey' => $tempKeys['credentials']['tmpSecretKey'],
    'SessionToken' => $tempKeys['credentials']['sessionToken'],
    'StartTime' => time(),
    'ExpiredTime' => $tempKeys['expiredTime'],
    'Bucket' => $bucket,
    'Region' => $region,
    'Key' => $allowedPrefixes,
  );
  echo json_encode(['credentials'=>$resTemp]);
  return $resTemp;
}
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['file-name'])) {
    $filename = $_POST['file-name'];
    getKeyAndCredentials($filename);
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}
?>
<?php
require_once 'src/Sts.php';
require_once 'src/Scope.php';
require_once 'src/cos-sdk-v5-7/tencent-php/vendor/autoload.php';
require 'global_variables.php';

$secretId = $cos_secret_id; //替换为用户的 secretId，请登录访问管理控制台进行查看和管理，https://console.cloud.tencent.com/cam/capi
$secretKey = $cos_secret_key; //替换为用户的 secretKey，请登录访问管理控制台进行查看和管理，https://console.cloud.tencent.com/cam/capi
$region = $cos_region; //替换为用户的 region，已创建桶归属的region可以在控制台查看，https://console.cloud.tencent.com/cos5/bucket
$cosClient = new Qcloud\Cos\Client(
    array(
        'region' => $region, //协议头部，默认为http
        'scheme' => 'https',
        'signHost' => true, //默认签入Header Host；您也可以选择不签入Header Host，但可能导致请求失败或安全漏洞,若不签入host则填false
        'credentials'=> array(
            'secretId'  => $secretId ,
            'secretKey' => $secretKey)));

### 简单下载预签名
try {
    $signedUrl = $cosClient->getPreSignedUrl('getObject', array(
        'Bucket' => $cos_bucket, //存储桶，格式：BucketName-APPID
        'Key' => $_POST['file_key'], //对象在存储桶中的位置，即对象键
        'Params'=> array(), //http 请求参数，传入的请求参数需与实际请求相同，能够防止用户篡改此HTTP请求的参数,默认为空
        'Headers'=> array(), //http 请求头部，传入的请求头部需包含在实际请求中，能够防止用户篡改签入此处的HTTP请求头部,默认已签入host
        ), '+1 minutes'); //签名的有效时间
    // 请求成功
    http_response_code(200);
    echo ($signedUrl);
} catch (\Exception $e) {
    // 请求失败
    http_response_code(500);
    logException($e);
    echo json_encode(['message'=>"Internal Server Error"]);
}

<?php
// CustomPDO.php

require_once 'CustomPDOStatement.php';
require_once 'global_error_handler.php';

class CustomPDO extends PDO
{
    public function __construct($dsn, $username = null, $password = null, $options = [])
    {
        // 确保正确合并默认选项和用户传递的选项
        $options = array_merge([
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_STATEMENT_CLASS    => ['CustomPDOStatement', []],
        ], $options);

        // 调用父类构造函数
        parent::__construct($dsn, $username, $password, $options);
    }

    // 捕获所有调用的异常
    public function __call($name, $arguments)
    {
        try {
            return call_user_func_array([$this, $name], $arguments);
        } catch (PDOException $e) {
            customExceptionHandler($e);  // 调用日志记录函数
            throw $e;           // 重新抛出异常，确保外部调用者能处理
        }
    }
}

?>
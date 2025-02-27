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
            logException($e);  // 使用logException函数记录异常
            throw $e;           // 重新抛出异常，确保外部调用者能处理
        }
    }
    
    // 重写prepare方法以实现性能优化和错误处理
    public function prepare($statement, $options = array())
    {
        try {
            // 使用持久化预处理语句，提高性能
            static $preparedStatements = array();
            
            // 生成唯一键以缓存预处理语句
            $key = md5($statement . serialize($options));
            
            // 如果语句已经预处理过，直接返回缓存的语句
            if (isset($preparedStatements[$key])) {
                return $preparedStatements[$key];
            }
            
            // 预处理新语句并缓存
            $preparedStatements[$key] = parent::prepare($statement, $options);
            return $preparedStatements[$key];
        } catch (PDOException $e) {
            logException($e);
            throw $e;
        }
    }
    
    // 添加事务辅助方法，简化事务处理并增加错误处理
    public function executeTransaction(callable $callback)
    {
        try {
            $this->beginTransaction();
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (Exception $e) {
            $this->rollBack();
            logException($e);
            throw $e;
        }
    }
}

?>

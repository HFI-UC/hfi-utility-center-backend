<?php
// CustomPDOStatement.php

require_once 'global_error_handler.php'; // 确保包含异常处理器

class CustomPDOStatement extends PDOStatement
{
    protected function __construct()
    {
    }

    public function __call($name, $arguments)
    {
        try {
            if ($name === 'fetch' || $name === 'fetchAll') {
                // 捕获异常并调用 logException
                return call_user_func_array([$this, $name], [PDO::FETCH_ASSOC]);
            }

            // 对于其他方法，正常调用
            return call_user_func_array([$this, $name], $arguments);

        } catch (PDOException $e) {
            // 调用日志记录函数
            logException($e);
            // 重新抛出异常，确保外部调用者能处理
            throw $e;
        }
    }
    
    // 重写 execute 方法，添加性能优化和错误处理
    public function execute($params = null)
    {
        try {
            $startTime = microtime(true);
            $result = parent::execute($params);
            $executionTime = microtime(true) - $startTime;
            
            // 如果查询执行时间超过一定阈值，记录为慢查询
            if ($executionTime > 0.1) { // 100ms阈值，可调整
                error_log(sprintf(
                    "慢查询警告: %.4f秒 - %s", 
                    $executionTime, 
                    $this->queryString
                ));
            }
            
            return $result;
        } catch (PDOException $e) {
            logException($e);
            throw $e;
        }
    }
}

?>

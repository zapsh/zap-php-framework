<?php

namespace zap\cache;

class RedisCache implements CacheInterface
{

    /**
     * @var object|\Redis|null
     */
    protected $redis;

    /**
     * 键前缀：用于与同一 Redis 库中的其它应用隔离，
     * clear() 时只按前缀删除，避免 flushDB 清空整库。
     *
     * @var string
     */
    protected $prefix = '';

    public function __construct($options = null)
    {
        $default = ['client'=>'redis'];
        $options += $default;

        $redisClass = '\Redis';
        if($options['client'] == 'predis'){
            $redisClass = '\Predis\Client';
            $reflect = new \ReflectionClass($redisClass);
            $this->redis = $reflect->newInstanceArgs($options['params']);
        }else{
            $this->redis = new $redisClass();
            call_user_func_array([$this->redis,'connect'],$options['params']);
        }

        // 可选：密码认证与库选择
        if(!empty($options['password'])){
            $this->redis->auth($options['password']);
        }
        if(isset($options['database']) && (int)$options['database'] > 0){
            $this->redis->select((int)$options['database']);
        }

        $this->prefix = (string)($options['prefix'] ?? '');
    }

    /**
     * 生成实际使用的缓存键（自动加前缀）
     */
    protected function key($key)
    {
        return $this->prefix === '' ? $key : $this->prefix . $key;
    }

    public function get($key, $default = null, $ttl = null)
    {
        $value = $this->redis->get($this->key($key));

        if($value !== false){
            return $value;
        }

        if(is_callable($default)){
            $data = $default();
            if($data !== null){
                $this->set($key,$data,$ttl);
                return $data;
            }
        }

        return $default;
    }

    public function set($key, $value, $ttl = null) {

        if($ttl === null){
            return $this->redis->set($this->key($key), $value);
        }

        return $this->redis->setex($this->key($key), $ttl, $value);
    }

    public function delete($key)
    {
        return (bool)$this->redis->del($this->key($key));
    }

    /**
     * 清空缓存。
     *
     * 配置了 prefix 时仅删除该前缀下的键（SCAN + DEL 分批），
     * 未配置 prefix 时保持原有行为（flushDB）。
     */
    public function clear()
    {
        if($this->prefix === ''){
            return $this->redis->flushDB();
        }

        $pattern = $this->prefix . '*';

        // phpredis / RedisCluster：scan 使用引用游标
        if($this->redis instanceof \Redis){
            $iterator = null;
            do{
                $keys = $this->redis->scan($iterator, $pattern, 500);
                if(!empty($keys)){
                    $this->redis->del($keys);
                }
            }while((int)$iterator !== 0);

            return true;
        }

        // Predis：scan 返回 [cursor, keys]
        $cursor = '0';
        do{
            [$cursor, $keys] = $this->redis->scan($cursor, ['MATCH' => $pattern, 'COUNT' => 500]);
            if(!empty($keys)){
                $this->redis->del($keys);
            }
            $cursor = (string)$cursor;
        }while($cursor !== '0');

        return true;
    }

    public function getMultiple($keys, $default = null)
    {
        $items = [];
        foreach ($keys as $key){
            $items[$key] = $this->get($key, is_array($default) ? $default[$key] : $default);
        }
        return $items;
    }

    public function setMultiple($values, $ttl = null) {
        foreach($values as $key => $value){
            $this->set($key,$value,$ttl);
        }
    }

    public function deleteMultiple($keys)
    {
        foreach ($keys as $key){
            $this->delete($key);
        }
    }

    public function has($key)
    {
        return (bool)$this->get($key);
    }

    public function increment($key, $initValue = null){
        $value = $this->get($key);
        if(is_null($value)){
            $value = $initValue ?? 0;
        }else{
            $value = $initValue ? $value+$initValue : $value+1;
        }
        $this->set($key,$value);
        return $value;
    }

    public function decrement($key, $initValue = null){
        $value = $this->get($key);
        if(is_null($value)){
            $value = $initValue ?? 0;
        }else{
            $value = $initValue ? $value-$initValue : $value-1;
        }
        $this->set($key,$value);
        return $value;
    }

    public function pull($key,$default = null){
        $value = $this->get($key,$default);
        $this->delete($key);
        return $value;
    }


}

<?php

namespace zap\cache;

class FileCache implements CacheInterface
{
    protected $cacheDir;

    protected bool $isCache = true;

    public function __construct($options = null)
    {
        if(!isset($options['cacheDir'])){
            throw new CacheException('unset cacheDir');
        }

        $this->cacheDir = $options['cacheDir'];
        $this->isCache = $options['isCache'] == 'enabled';

        if(!is_dir($this->cacheDir)){
            if(!@mkdir($this->cacheDir, 0755, true) && !is_dir($this->cacheDir)){
                throw new CacheException('invalid cacheDir "'.$this->cacheDir.'"');
            }
        }

        if(!is_writable($this->cacheDir)){
            throw new CacheException('cacheDir is read-only. permissions?');
        }
    }

    public function get($key, $default = null, $ttl = null)
    {
        $filename = $this->getFilePath($key);

        if(is_file($filename) && $this->isCache){
            $content = file_get_contents($filename);

            if(!empty($content)){
                // 关键安全点：只允许还原 stdClass（本类自身的容器结构），
                // 任何其它类都会被降级为 __PHP_Incomplete_Class 而不会实例化，
                // 因此缓存文件即便被投毒也无法触发 POP 链执行任意代码。
                $data = @unserialize($content, ['allowed_classes' => ['stdClass']]);

                // 注意：ttl 为 null 表示永不过期，因此不能用 isset() 判断——
                // isset() 遇到 null 会返回 false，会把永久缓存（如路由缓存）误判为无效。
                if($data instanceof \stdClass && property_exists($data, 'ttl') && property_exists($data, 'content')){
                    $notExpired = ($data->ttl === null || $data->ttl > time());

                    // 调用方在读取时又指定了 ttl：按写入时间再做一次判定
                    if($notExpired && $ttl !== null && isset($data->created_at)
                        && ($data->created_at + $ttl) <= time()){
                        $notExpired = false;
                    }

                    if($notExpired){
                        return $data->content;
                    }

                    @unlink($filename);
                } else {
                    // 数据损坏或格式不符：直接丢弃，避免返回不可预期的值
                    @unlink($filename);
                }
            }

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

        $file = $this->getFilePath($key);
        $dirPath  = dirname($file);

        if(!is_dir($dirPath)){
            mkdir($dirPath, 0755, true);
        }

        $data             = new \stdClass;
        $data->created_at = time();
        $data->ttl        = ($ttl !== null && $ttl > 0) ? time() + $ttl : null;
        $data->content    = $value;

        return file_put_contents($file, serialize($data));
    }

    public function delete($key)
    {
        $filename = $this->getFilePath($key);

        if(is_file($filename)){
            return unlink($filename);
        }

        return false;
    }

    public function clear()
    {
        $iterator = new \RecursiveDirectoryIterator($this->cacheDir, \FilesystemIterator::CURRENT_AS_PATHNAME|\FilesystemIterator::SKIP_DOTS);

        foreach(new \RecursiveIteratorIterator($iterator) as $path){
            unlink($path);
        }

        return true;
    }

    public function getMultiple($keys, $default = null,$ttl = null)
    {
        $items = [];
        foreach ($keys as $key){
            $items[$key] = $this->get($key,is_array($default) ? $default[$key] : $default,$ttl);
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
        return $this->get($key) !== null;
    }

    protected function getFilePath($key){
        $hash = hash('sha1', $key);
        return $this->cacheDir.DIRECTORY_SEPARATOR.$hash[0].$hash[1].DIRECTORY_SEPARATOR.$hash;
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
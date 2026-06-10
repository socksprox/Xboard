<?php

namespace App\Support;

use App\Models\Setting as SettingModel;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Contracts\Cache\Repository;

class Setting
{
    const CACHE_KEY = 'admin_settings';

    private Repository $cache;
    private ?array $loadedSettings = null; // 请求内缓存

    public function __construct()
    {
        $this->cache = Cache::store('redis');
    }

    /**
     * 获取配置.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->load();
        return Arr::get($this->loadedSettings, strtolower($key), $default);
    }

    /**
     * 设置配置信息.
     */
    public function set(string $key, mixed $value = null): bool
    {
        SettingModel::createOrUpdate(strtolower($key), $value);
        $this->flush();
        return true;
    }

    /**
     * 保存配置到数据库.
     */
    public function save(array $settings): bool
    {
        foreach ($settings as $key => $value) {
            SettingModel::createOrUpdate(strtolower($key), $value);
        }
        $this->flush();
        return true;
    }

    /**
     * 删除配置信息
     */
    public function remove(string $key): bool
    {
        SettingModel::where('name', $key)->delete();
        $this->flush();
        return true;
    }

    /**
     * 更新单个设置项
     */
    public function update(string $key, $value): bool
    {
        return $this->set($key, $value);
    }
    
    /**
     * 批量获取配置项
     */
    public function getBatch(array $keys): array
    {
        $this->load();
        $result = [];
        
        foreach ($keys as $index => $item) {
            $isNumericIndex = is_numeric($index);
            $key = strtolower($isNumericIndex ? $item : $index);
            $default = $isNumericIndex ? config('v2board.' . $item) : (config('v2board.' . $key) ?? $item);
            
            $result[$item] = Arr::get($this->loadedSettings, $key, $default);
        }
        
        return $result;
    }
    
    /**
     * 将所有设置转换为数组
     */
    public function toArray(): array
    {
        $this->load();
        return $this->loadedSettings;
    }

    /**
     * 清空进程内缓存，下次访问时重新从共享缓存/数据库加载。
     *
     * 长生命周期的 worker（如 Workerman ws-server）会在整个进程生命周期内
     * 持有同一个 Setting 实例，没有请求/任务级别的作用域重置。调用本方法可
     * 让 worker 重新读取面板中最新的配置（例如轮换后的 server_token），并从
     * 一次失败的初始加载中自愈。
     */
    public function refresh(): void
    {
        $this->loadedSettings = null;
    }

    /**
     * 加载配置到请求内缓存
     */
    private function load(): void
    {
        if ($this->loadedSettings !== null) {
            return;
        }

        try {
            $settings = $this->cache->rememberForever(self::CACHE_KEY, function (): array {
                return array_change_key_case(
                    SettingModel::pluck('value', 'name')->toArray(),
                    CASE_LOWER
                );
            });
            
            // 处理JSON格式的值
            foreach ($settings as $key => $value) {
                if (is_string($value)) {
                    $decoded = json_decode($value, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $settings[$key] = $decoded;
                    }
                }
            }
            
            $this->loadedSettings = $settings;
        } catch (\Throwable $e) {
            // 不要把空数组写入进程内缓存：那会让当前实例在其整个生命周期内
            // 永久处于“配置为空”的损坏状态，对从不重建实例的长生命周期 worker
            // （ws-server/Workerman）是灾难性的（会导致 server_token 恒为空、
            // 所有节点鉴权失败）。保持 $loadedSettings 为 null，使下一次访问在
            // 后端存储恢复后可以重试加载。
            report($e);
        }
    }

    /**
     * 清空缓存
     */
    private function flush(): void
    {
        $this->cache->forget(self::CACHE_KEY);
        $this->loadedSettings = null;
    }
}

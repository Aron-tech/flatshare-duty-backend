<?php

namespace App\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

trait DataTrait
{
    protected function getJsonColumnName(): string
    {
        return property_exists($this, 'json_data_column') ? $this->json_data_column : 'data';
    }

    public function setData(string $json_key, mixed $value): Model
    {
        $column_name = $this->getJsonColumnName();
        $current_data = $this->getAttribute($column_name) ?? [];

        if (is_string($current_data)) {
            $current_data = json_decode($current_data, true) ?: [];
        }

        Arr::set($current_data, $json_key, $value);

        $this->setAttribute($column_name, $current_data);

        return $this;
    }

    public function getData(string $json_key, mixed $default_value = null): mixed
    {
        $column_name = $this->getJsonColumnName();
        $current_data = $this->getAttribute($column_name) ?? [];

        if (is_string($current_data)) {
            $current_data = json_decode($current_data, true) ?: [];
        }

        return Arr::get($current_data, $json_key, $default_value);
    }

    public function hasData(string $json_key): bool
    {
        $column_name = $this->getJsonColumnName();
        $current_data = $this->getAttribute($column_name) ?? [];

        if (is_string($current_data)) {
            $current_data = json_decode($current_data, true) ?: [];
        }

        return Arr::has($current_data, $json_key);
    }
}

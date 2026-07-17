<?php

namespace App\Modules\External\Services\Source;

use App\Models\ExternalSource;
use App\Modules\External\DTOs\ExternalSourceDto;

class ExternalSourceService
{
    public function __construct(private ExternalSource $externalSourceModel)
    {
    }

    /**
     * Добавление
     *
     * @param ExternalSourceDto $data
     *
     * @return ExternalSource
     *
     * @throws \Exception
     */
    public function create(ExternalSourceDto $data): ExternalSource
    {
        return $this->externalSourceModel
            ->create($data->toArray())
            ->getModel();
    }

    /**
     * Обновление
     *
     * @param ExternalSourceDto $data
     *
     * @return ExternalSource
     *
     * @throws \Exception
     */
    public function update(ExternalSourceDto $data): ExternalSource
    {
        $this->externalSourceModel
            ->where('id', $data->id)
            ->update($data->toArray());

        return $this->externalSourceModel->where('id', $data->id)->first();
    }
}

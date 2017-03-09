<?php

namespace DreamFactory\Core\ApiDoc\Models;

use DreamFactory\Core\Models\BaseSystemModel;
use Illuminate\Database\Query\Builder;

/**
 * ServiceDoc
 *
 * @property integer $service_id
 * @property integer $format
 * @property string  $content
 * @method static Builder|ServiceDoc whereId($value)
 * @method static Builder|ServiceDoc whereServiceId($value)
 * @method static Builder|ServiceDoc whereFormat($value)
 */
class ServiceDoc extends BaseSystemModel
{
    protected $table = 'service_doc';

    protected $primaryKey = 'service_id';

    protected $fillable = ['service_id', 'format', 'content'];

    protected $hidden = ['id'];

    protected $casts = ['service_id' => 'integer', 'format' => 'integer'];
}
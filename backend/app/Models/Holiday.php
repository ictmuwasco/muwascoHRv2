<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Holiday Model
 *
 * Represents a public holiday.
 */
class Holiday extends BaseModel
{
    protected static string $table = 'holidays';
    protected static string $primaryKey = 'id';
    protected static array $fillable = [
        'name',
        'date',
        'description',
        'is_recurring',
    ];
    protected static array $guarded = ['id', 'created_at', 'updated_at'];
    protected static bool $timestamps = true;
}
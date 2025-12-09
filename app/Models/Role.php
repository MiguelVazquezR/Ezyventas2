<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\Guard;
use Spatie\Permission\Exceptions\RoleAlreadyExists;
use Spatie\Permission\Contracts\Role as RoleContract;
use Spatie\Permission\PermissionRegistrar;

class Role extends SpatieRole
{
    /**
     * Sobrescribimos el método create para soportar branch_id y evitar el error de duplicados globales.
     * * @param array $attributes
     * @return RoleContract
     * @throws RoleAlreadyExists
     */
    public static function create(array $attributes = []): RoleContract
    {
        $attributes['guard_name'] = $attributes['guard_name'] ?? Guard::getDefaultName(static::class);

        // 1. Configuración básica de parámetros de búsqueda
        $params = ['name' => $attributes['name'], 'guard_name' => $attributes['guard_name']];

        // 2. Soporte nativo de Spatie Teams (lo dejamos por compatibilidad futura)
        if (app(PermissionRegistrar::class)->teams) {
            $teamsKey = app(PermissionRegistrar::class)->teamsKey;
            if (array_key_exists($teamsKey, $attributes)) {
                $params[$teamsKey] = $attributes[$teamsKey];
            } else {
                $attributes[$teamsKey] = getPermissionsTeamId();
            }
        }

        // 3. NUESTRO CAMBIO: Soporte para Branch ID
        // Si estamos creando un rol con sucursal, la incluimos en la búsqueda de unicidad
        if (isset($attributes['branch_id'])) {
            $params['branch_id'] = $attributes['branch_id'];
        }

        // 4. Verificación de existencia personalizada
        // Usamos query() para asegurarnos de buscar en la BD con los parámetros exactos (incluyendo branch_id)
        if (static::query()->where($params)->exists()) {
             throw RoleAlreadyExists::create($attributes['name'], $attributes['guard_name']);
        }

        // 5. Creación del rol
        return static::query()->create($attributes);
    }
}
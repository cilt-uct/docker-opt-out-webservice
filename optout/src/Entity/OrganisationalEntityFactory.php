<?php

namespace App\Entity;

use App\Entity\Course;
use App\Entity\Department;

class OrganisationalEntityFactory
{
    public static function getEntity($entityType, $entityName) {
        $entity = null;
        switch($entityType) {
            case 'course':
                $entity =  new Course($entityName, null, null, true);
                break;

            case 'dept':
                $entity =  new Department($entityName, null, null, true);
                break;

            default:
                throw new \Exception("Unknown entity type requested");
        }

        return $entity;
    }
}

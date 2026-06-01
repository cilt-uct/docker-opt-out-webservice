<?php

namespace App\Entity;

interface OrganisationalEntityInterface
{
    public function fetchDetails();
    public function updateOptoutStatus($user, $data, $workflow_id);
}

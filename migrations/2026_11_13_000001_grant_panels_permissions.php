<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

/*
 * Starting series is for moderators by default (copyright risk): the admin grants a
 * "Creators" group. Explicit and downloads are granted to nobody.
 */
return Migration::addPermissions([
    'panels.view' => Group::GUEST_ID,
    'panels.viewMature' => Group::MEMBER_ID,
    'panels.rate' => Group::MEMBER_ID,
    'panels.create' => Group::MODERATOR_ID,
    'panels.publishWithoutApproval' => Group::MODERATOR_ID,
    'panels.moderate' => Group::MODERATOR_ID,
]);

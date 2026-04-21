<?php

use Flarum\Database\Migration;

return Migration::addColumns('posts', [
    'via_mail_reply' => ['boolean', 'default' => false],
]);

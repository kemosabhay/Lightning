<?php

namespace Lightning\Database\Schema;

use Lightning\Database\Schema;

class MessageMessageList extends Schema {

    protected $table = 'message_message_list';

    public function getColumns() {
        return array(
            'message_id' => $this->int(true),
            'message_list_id' => $this->int(true),
        );
    }

    public function getKeys() {
        return array(
            'primary' => array(
                'columns' => array('message_id', 'message_list_id')
            ),
        );
    }
}

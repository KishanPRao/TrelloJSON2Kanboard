<?php

namespace Kanboard\Plugin\TrelloJSON2Kanboard\Model;

use Kanboard\Core\Base;

/**
 * TrelloJSON2Kanboard Model
 *
 * @package  Kanboard\Plugin\TrelloJSON2Kanboard\Model
 * @author   Wilton Rodrigues
 */
class TrelloJSON2KanboardModel extends Base
{
    public function parserJSON($jsonObj)
    {
        $project = new Project($jsonObj->name);

        $metadata = array('closed_columns' => array());
        //getting columns from JSON file
        foreach ($jsonObj->lists as $list) {
            // Add project metadata for archived lists / closed columns
            if ($list->closed) {
                array_push($metadata['closed_columns'], $list->id);
            }
            //creating column
            $column = new Column($list->name, $list->id);
            array_push($project->columns, $column);
        }
        $project->metadata = $metadata;

        foreach ($jsonObj->cards as $card) {
            $is_active = $card->closed ? 0 : 1;    // Assume archived/archived cards as inactive tasks.
            $due_date = $card->due !== null ? date('Y-m-d H:i', strtotime($card->due)) : null;
            $date_completed = null;
            $date_creation = $card->dateLastActivity !== null ? date('Y-m-d H:i', strtotime($card->dateLastActivity)) : null;
            $date_modification = $card->dateLastActivity !== null ? date('Y-m-d H:i', strtotime($card->dateLastActivity)) : null;
            $task = new Task($card->name, $card->id, $due_date, $card->desc, $is_active, $date_completed, $date_creation, $date_modification);
            $column_id = $this->card_column_id($project->columns, $card->idList);
            if (!is_null($column_id)) {
                array_push($project->columns[$column_id]->tasks, $task);
            }

            if ($card->badges->attachments > 0) {
                foreach ($card->attachments as $att) {
                    //only get attachments that are uploaded files
                    if ($att->isUpload) {
                        $attachment = new Attachment($att->name, $att->url);
                        array_push($task->attachments, $attachment);
                    } else {
                        // just an url, add a comment
                        $comment = new Comment(t('Attachment is just a link: %s', $att->url));
                        array_push($task->comments, $comment);
                    }
                }
            }
            $metadata = array('checklists' => array());
            $checklistPos = 0;
            foreach ($jsonObj->checklists as $checklist) {
                if ($checklist->idCard === $card->id) {
                    $value = array(
                        "name" => $checklist->name,
                        "position" => ++$checklistPos,
                        "items" => array()
                    );
                    foreach ($checklist->checkItems as $checkitem) {
                        array_push($value['items'], array(
                            "id" => $checkitem->id
                        ));
                    }
                    array_push($metadata['checklists'], $value);
                }
            }
            $task->metadata = $metadata;
        }

        //getting actions from JSON file
        foreach ($jsonObj->actions as $action) {
            //only get actions from commentCard type
            if ($action->type == 'commentCard') {
                //only get comments that belongs to this card
                $values = $this->comment_card_id($project->columns, $action->data->card->id);
                $comment = new Comment($action->data->text);
                if (!is_null($values)) {
                    array_push($project->columns[$values['column_key']]->tasks[$values['task_key']]->comments, $comment);
                }
            }
        }

        foreach ($jsonObj->checklists as $checklist) {
            //only get checklists that belongs to this card
            $values = $this->checkitem_card_id($project->columns, $checklist->idCard);
            if (!is_null($values)) {
                $checklistItems = $checklist->checkItems;
                usort($checklistItems, function ($a, $b) {
                    return $a->pos <=> $b->pos;
                });
                foreach ($checklistItems as $checkitem) {
                    $status = $checkitem->state == 'complete' ? 2 : 0;
                    $subtask = new Subtask($checkitem->name, $status, $checkitem->id);
                    array_push($project->columns[$values['column_key']]->tasks[$values['task_key']]->subtasks, $subtask);
                }
            }
        }

        return $project;
    }

    public function card_column_id($columns, $value)
    {
        foreach ($columns as $task_key => $card) {
            if ($card->trello_id == $value) {
                return $task_key;
            }
        }
    }

    public function comment_card_id($columns, $value)
    {
        foreach ($columns as $column_key => $column) {
            foreach ($column->tasks as $task_key => $task) {
                if ($task->trello_id == $value) {
                    return array(
                        'column_key' => $column_key,
                        'task_key' => $task_key,
                    );
                }
            }
        }
    }

    public function checkitem_card_id($columns, $value)
    {
        foreach ($columns as $column_key => $column) {
            foreach ($column->tasks as $task_key => $task) {
                if ($task->trello_id == $value) {
                    return array(
                        'column_key' => $column_key,
                        'task_key' => $task_key,
                    );
                }
            }
        }
    }
}

class Project
{
    public $name;
    public $columns = array();
    var $metadata;

    public function __construct($name)
    {
        $this->name = $name;
    }
}

class Column
{
    var $name;
    var $trello_id;
    var $tasks = array();

    function __construct($name, $trello_id)
    {
        $this->name = $name;
        $this->trello_id = $trello_id;
    }
}

class Task
{
    var $name;
    var $trello_id;
    var $date_due;
    var $desc;
    var $subtasks = array();
    var $comments = array();
    var $attachments = array();
    var $metadata;
    var $is_active;
    var $date_completed;
    var $date_creation;
    var $date_modification;

    function __construct($name, $trello_id, $date_due, $desc,  $is_active, $date_completed, $date_creation, $date_modification)
    {
        $this->name = $name;
        $this->trello_id = $trello_id;
        $this->date_due = $date_due;
        $this->desc = $desc;
        $this->is_active = $is_active;
        $this->date_completed = $date_completed;
        $this->date_creation = $date_creation;
        $this->date_modification = $date_modification;
    }
}

class Subtask
{
    var $content;
    var $status;
    var $trello_id;

    function __construct($content, $status, $trello_id)
    {
        $this->content = $content;
        $this->status = $status;
        $this->trello_id = $trello_id;
    }
}

class Comment
{
    var $content;

    function __construct($content)
    {
        $this->content = $content;
    }
}

class Attachment
{
    var $filename;
    var $url;

    function __construct($filename, $url)
    {
        $this->filename = $filename;
        $this->url = $url;
    }
}

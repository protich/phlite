<?php

namespace Phlite\Db\Backends\MySQL;

use Phlite\Db;
use Phlite\Db\Exception;
use Phlite\Db\Compile\SqlExecutor;
use Phlite\Db\Compile\Statement;

class MySQLiExecutor
implements SqlExecutor {

    var $stmt;
    var $fields = array();
    
    var $backend;
    var $conn;
    var $res;   // Server resource / cursor

    function __construct(Statement $stmt, Db\Backend $bk) {
        $this->stmt = $stmt;
        $this->backend = $bk;
    }
    
    // Array of [count, model] values representing which fields in the
    // result set go with witch model.  Useful for handling select_related
    // queries
    function getMap() {
        return $this->stmt->map;
    }

    function execute() {
        if (!isset($this->conn))
            $this->conn = $this->backend->getConnection();
        
        // TODO: Detect server/client abort, pause and attempt reconnection
        
        if (!($this->res = $this->conn->prepare($this->stmt->sql)))
            throw new Exception\InconsistentModel(
                'Unable to prepare query: '.$this->conn->error
                .': '.$this->stmt->sql);
        
        // TODO: Implement option to drop parameters
        
        if ($this->stmt->hasParameters())
            $this->_bind($this->stmt, $this->res);
        if (!$this->res->execute() || !$this->res->store_result()) {
            throw new Exception\DbError('Unable to execute query: ' . $this->res->error);
        }
        $this->_setup_output();
        return true;
    }
    
    /**
     * Remove the parameters from the string and replace them with escaped
     * values. This is reportedly faster for MySQL and equally as safe.
     */
    function _unparameterize() {
        if (!isset($this->conn))
            $this->conn = $this->backend->getConnection();
        $conn = $this->conn;
        return $this->stmt->toString(function($i) use ($conn) {
            $q = $self->conn->real_escape($i);
            if (is_string($i))
                return "'$i'";
            return $i;            
        });
    }

    function _bind(Statement $stmt, $res) {
        $params = $stmt->getParameters();
        if (count($params) != $res->param_count)
            throw new Exception\OrmError('Parameter count does not match query');

        $types = '';
        $ps = array();
        foreach ($params as &$p) {
            if (is_int($p) || is_bool($p))
                $types .= 'i';
            elseif (is_float($p))
                $types .= 'd';
            elseif (is_string($p))
                $types .= 's';
            elseif ($p instanceof \DateTime) {
                // TODO: Detect database timezone and convert accordingly
                // for a non-naive DateTime instance
                $types .= 's';
                $p = $p->format('Y-m-d h:i:s');
            }
            // FIXME: Emit error if param is null
            $ps[] = &$p;
        }
        unset($p);
        array_unshift($ps, $types);
        call_user_func_array(array($res, 'bind_param'), $ps);
    }

    function _setup_output() {
        if (!($meta = $this->res->result_metadata()))
            throw new Exception\DbError(
                'Unable to fetch statment metadata: ', $this->res->error);
        $this->fields = $meta->fetch_fields();
        $meta->free_result();
    }

    // Iterator interface
    function rewind() {
        if (!isset($this->res))
            $this->execute();
        $this->res->data_seek(0);
    }

    function next() {
        $status = $this->res->fetch();
        if ($status === false)
            throw new Exception\DbError($this->res->error);
        elseif ($status === null) {
            $this->close();
            return false;
        }
        return true;
    }

    function fetchArray() {
        $output = array();
        $variables = array();

        if (!isset($this->res))
            $this->execute();

        foreach ($this->fields as $f)
            $variables[] = &$output[$f->name]; // pass by reference

        if (!call_user_func_array(array($this->res, 'bind_result'), $variables))
            throw new Exception\OrmError('Unable to bind result: ' . $this->res->error);

        if (!$this->next())
            return false;
        return $output;
    }

    function fetchRow() {
        $output = array();
        $variables = array();

        if (!isset($this->res))
            $this->execute();

        foreach ($this->fields as $f)
            $variables[] = &$output[]; // pass by reference

        if (!call_user_func_array(array($this->res, 'bind_result'), $variables))
            throw new Exception\OrmError('Unable to bind result: ' . $this->res->error);

        if (!$this->next())
            return false;
        return $output;
    }

    function close() {
        if (!$this->res)
            return;

        $this->res->close();
        $this->res = null;
    }

    function affected_rows() {
        return $this->res->affected_rows;
    }

    function insert_id() {
        return $this->res->insert_id;
    }
}
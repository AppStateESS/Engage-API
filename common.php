<?php

function wrapPDO(PDOStatement $stmt, $error = null, array $values = null)
{
    $ret = $stmt->execute($values);

    if($error == null) return $ret;
    if(!$ret) {
        echo "$error\n";
        var_dump($stmt->errorInfo());
        throw new Exception('derp');
    }

    return $ret;
}

function reportSoap($function, $result, array $values, $string = null)
{
    $vals = array();
    foreach($values as $key => $val) {
        $vals[] = "$key='$val'";
    }
    $valStr = implode(', ', $vals);

    if(!$string) $string = '';
    else $string = " ($string)";
    return "$function($valStr) = $result$string";
}

?>

<?php

require_once __DIR__ . '/../vendor/autoload.php';

$query = json_decode($_POST['q'], true);

try {

    print_r(build_rules($query));
    echo '<br />';

    $client = new \Solarium\Client();
    $select = $client->createSelect();
    $select->setQuery(build_rules($query));
    $result = $client->select($select);

    print_r($result->getDocuments());

} catch (Exception $e) {
    var_dump($e);
}


/**
 * @param array $rules
 *
 * @return string
 *
 * @throws Exception
 */
function build_rules($rules)
{
    foreach (array('rules', 'condition') as $param) {
        if (! isset($rules[$param])) {
            throw new Exception('could not find required param: ' . $param);
        }
    }

    if (!in_array($rules['condition'], array('AND', 'OR'))) {
        throw new Exception(__FUNCTION__ . ' can not handle condition: ' . $rules['condition']);
    }

    $strings = array();
    foreach ($rules['rules'] as $rule) {
        $strings[] = build_rule($rule);
    }

    if (empty($strings)) {
        return '*:*';
    }

    return '(' . join(' ' . $rules['condition'] . ' ', $strings) . ')';
}


/**
 * @param array $rule
 *
 * @return string
 *
 * @throws Exception
 */
function build_rule($rule)
{
    if (isset($rule['condition'])) {
        return build_rules($rule);
    }

    if (! isset($rule['field'])) {
        throw new Exception('could not find required param: field');
    }

    if (in_array($rule['field'], array('subject', 'body', 'from_names', 'to_names', 'cc_names'))) {
        return build_rule_string($rule);
    }

    if (in_array($rule['field'], array('from', 'to', 'cc'))) {
        return build_rule_email($rule);
    }

    switch ($rule['field']) {
        case 'involves-address':
            return build_rule_involves_address($rule);

        case 'involves-name':
            return build_rule_involves_name($rule);

        case 'date':
            return build_rule_date($rule);
    }

    throw new Exception('could not handle field: ' . $rule['field']);
}


/**
 * @param array $rule
 *
 * @return string
 *
 * @throws Exception
 */
function build_rule_date($rule)
{
    foreach (array('operator', 'value') as $param) {
        if (! isset($rule[$param])) {
            throw new Exception('could not find required param: ' . $param);
        }
    }

    if (! in_array($rule['operator'], array('less_or_equal', 'greater_or_equal', 'between'))) {
        throw new Exception(__FUNCTION__ . ' can not handle operator: ' . $rule['operator']);
    }

    $start = '*';
    $end = '*';

    switch ($rule['operator']) {
        case 'less_or_equal':
            $end = format_datetime($rule['value']);
            break;

        case 'greater_or_equal':
            $start = format_datetime($rule['value']);
            break;

        case 'between':
            $start = format_datetime($rule['value'][0]);
            $end = format_datetime($rule['value'][1]);
            break;
    }

    return 'date:[' . join(' TO ', array($start, $end)) . ']';
}


/**
 * @param array $rule
 *
 * @return string
 *
 * @throws Exception
 */
function build_rule_involves_address($rule)
{
    if (! isset($rule['value'])) {
        throw new Exception('could not find required param: value');
    }

    $value = escape_phrase($rule['value']);
    return '(' . join(' OR ', array("to:$value", "from:$value", "cc:$value")) . ')';
}

/**
 * @param array $rule
 *
 * @return string
 *
 * @throws Exception
 */
function build_rule_involves_name($rule)
{
    if (! isset($rule['value'])) {
        throw new Exception('could not find required param: value');
    }

    $value = escape_phrase($rule['value']);
    return '(' . join(' OR ', array("to_names:$value", "from_names:$value", "cc_names:$value")) . ')';
}


/**
 * @param array  $rule
 *
 * @return string
 *
 * @throws Exception
 */
function build_rule_email( $rule)
{
    foreach (array('operator', 'value', 'field') as $param) {
        if (! isset($rule[$param])) {
            throw new Exception('could not find required param: ' . $param);
        }
    }

    if (!in_array($rule['operator'], array('equal', 'not_equal'))) {
        throw new Exception(__FUNCTION__ . ' can not handle operator: ' . $rule['operator']);
    }

    $value = escape_phrase($rule['value']);
    $solrField = $rule['field'];

    if ($rule['operator'] == 'equal') {
        return "$solrField:$value";
    }

    if ($rule['operator'] == 'not_equal') {
        return "!$solrField:$value";
    }
}


/**
 * @param array $rule
 *
 * @return string
 *
 * @throws Exception
 */
function build_rule_string($rule)
{
    foreach (array('operator', 'value', 'field') as $param) {
        if (! isset($rule[$param])) {
            throw new Exception('could not find required param: ' . $param);
        }
    }

    if ($rule['operator'] !== 'contains') {
        throw new Exception(__FUNCTION__ . ' can not handle operator: ' . $rule['operator']);
    }

    $value = escape_phrase($rule['value']);
    $solrField = $rule['field'];
    return "$solrField:$value";
}


/**
 * @param string $input
 *
 * @return string
 */
function escape_phrase($input)
{
    return '"' . preg_replace('/("|\\\)/', '\\\$1', $input) . '"';
}


/**
 * @param string $string in format 2015-01-01 00:00:00
 *
 * @return string in format for Solr
 *
 * @throws Exception
 */
function format_datetime($string)
{
    $datetime = DateTime::createFromFormat('Y-m-d H:i:s', $string, new \DateTimeZone('UTC'));
    if (! $datetime) {
        throw new Exception('Failed to parse datetime: ' . $rule['value']);
    }
    return $datetime->format('Y-m-d\TH:i:s\Z');
}

<?php

namespace PhpQuery;

/**
 * @param $arg
 * @param null $context
 *
 * @return PhpQueryObject
 *
 * @throws \Exception
 */
function pq($arg, $context = NULL) : PhpQueryObject
{
    return PhpQuery::pq($arg, $context);
}

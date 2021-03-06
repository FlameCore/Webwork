<?php
/**
 * Infernum
 * Copyright (C) 2015 IceFlame.net
 *
 * Permission to use, copy, modify, and/or distribute this software for
 * any purpose with or without fee is hereby granted, provided that the
 * above copyright notice and this permission notice appear in all copies.
 *
 * @package  FlameCore\Infernum
 * @version  0.1-dev
 * @link     http://www.flamecore.org
 * @license  http://opensource.org/licenses/ISC ISC License
 */

namespace FlameCore\Infernum\Database;

/**
 * Result set returned by a database query
 *
 * @author   Christian Neff <christian.neff@gmail.com>
 */
abstract class AbstractResult implements ResultInterface
{
    /**
     * {@inheritdoc}
     */
    public function hasRows()
    {
        return $this->numRows() > 0;
    }
}

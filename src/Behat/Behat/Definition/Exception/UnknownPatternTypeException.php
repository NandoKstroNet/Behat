<?php

/*
 * This file is part of the Behat.
 * (c) Konstantin Kudryashov <ever.zet@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Behat\Behat\Definition\Exception;

use InvalidArgumentException;

/**
 * Behat unknown pattern type exception.
 *
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
class UnknownPatternTypeException extends InvalidArgumentException implements DefinitionException
{
    /**
     * @var string
     */
    private $type;

    /**
     * Initializes exception.
     *
     * @param string $message
     * @param string $type
     */
    public function __construct($message, $type)
    {
        $this->type = $type;

        parent::__construct($message);
    }

    /**
     * Returns pattern type that caused exception.
     *
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }
}

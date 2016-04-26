<?php
/**
 * This file is part of the Atline templating system package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * Copyright (c) 2015 - 2016 by Adam Banaszkiewicz
 *
 * @license   MIT License
 * @copyright Copyright (c) 2015 - 2016, Adam Banaszkiewicz
 * @link      https://github.com/requtize/atline
 */

namespace Requtize\Atline;

/**
 * @author Adam Banaszkiewicz https://github.com/requtize
 */
interface DefinitionResolverInterface
{
    /**
     * Translate $definition into path to view file.
     * 
     * @param  mixed $definition Definition to parse.
     * @return Filepath.
     */
    public function resolve($definition);
}

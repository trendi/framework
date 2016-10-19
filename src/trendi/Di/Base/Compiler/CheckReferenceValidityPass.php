<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Trendi\Di\Base\Compiler;

use Trendi\Di\Base\Definition;
use Trendi\Di\Base\Reference;
use Trendi\Di\Base\ContainerBuilder;
use Trendi\Di\Base\Exception\RuntimeException;

/**
 * Checks the validity of references.
 *
 * The following checks are performed by this pass:
 * - target definitions are not abstract
 *
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
class CheckReferenceValidityPass implements CompilerPassInterface
{
    private $container;
    private $currentId;

    /**
     * Processes the ContainerBuilder to validate References.
     *
     * @param ContainerBuilder $container
     */
    public function process(ContainerBuilder $container)
    {
        $this->container = $container;

        foreach ($container->getDefinitions() as $id => $definition) {
            if ($definition->isSynthetic() || $definition->isAbstract()) {
                continue;
            }

            $this->currentId = $id;

            $this->validateReferences($definition->getArguments());
            $this->validateReferences($definition->getMethodCalls());
            $this->validateReferences($definition->getProperties());
        }
    }

    /**
     * Validates an array of References.
     *
     * @param array $arguments An array of Reference objects
     *
     * @throws RuntimeException when there is a reference to an abstract definition.
     */
    private function validateReferences(array $arguments)
    {
        foreach ($arguments as $argument) {
            if (is_array($argument)) {
                $this->validateReferences($argument);
            } elseif ($argument instanceof Reference) {
                $targetDefinition = $this->getDefinition((string) $argument);

                if (null !== $targetDefinition && $targetDefinition->isAbstract()) {
                    throw new RuntimeException(sprintf(
                        'The definition "%s" has a reference to an abstract definition "%s". '
                       .'Abstract definitions cannot be the target of references.',
                       $this->currentId,
                       $argument
                    ));
                }
            }
        }
    }

    /**
     * Returns the Definition given an id.
     *
     * @param string $id Definition identifier
     *
     * @return Definition
     */
    private function getDefinition($id)
    {
        if (!$this->container->hasDefinition($id)) {
            return;
        }

        return $this->container->getDefinition($id);
    }
}
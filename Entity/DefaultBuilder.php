<?php
/**
 * @copyright 2014 Integ S.A.
 * @license http://opensource.org/licenses/MIT The MIT License (MIT)
 * @author Javier Lorenzana <javier.lorenzana@gointegro.com>
 */

namespace GoIntegro\Bundle\HateoasBundle\Entity;

// Inflection.
use Doctrine\Common\Util\Inflector;
// JSON-API.
use GoIntegro\Bundle\HateoasBundle\JsonApi\Request\Parser;
// ORM.
use Doctrine\ORM\EntityManagerInterface,
    Doctrine\ORM\ORMException;
// Validator.
use Symfony\Component\Validator\Validator\ValidatorInterface,
    GoIntegro\Bundle\HateoasBundle\Entity\Validation\ValidationException;
// Security.
use Symfony\Component\Security\Core\SecurityContextInterface;
// HTTP.
use Symfony\Component\HttpFoundation\Request;

class DefaultBuilder implements BuilderInterface
{
    const GET = 'get', ADD = 'add', SET = 'set';

    const AUTHOR_IS_OWNER = 'GoIntegro\\Bundle\\HateoasBundle\\Entity\\AuthorIsOwner',
        ERROR_COULD_NOT_CREATE = "Could not create the resource.";

    /**
     * @var EntityManagerInterface
     */
    private $em;
    /**
     * @var ValidatorInterface
     */
    private $validator;
    /**
     * @var SecurityContextInterface
     */
    private $securityContext;
    /**
     * @var Parser
     */
    private $parser;
    /**
     * @var request
     */
    private $request;

    /**
     * @param EntityManagerInterface $em
     * @param ValidatorInterface $validator
     * @param SecurityContextInterface $securityContext
     * @param Parser $parser
     * @param Request $request
     */
    public function __construct(
        EntityManagerInterface $em,
        ValidatorInterface $validator,
        SecurityContextInterface $securityContext,
        Parser $parser,
        Request $request
    )
    {
        $this->em = $em;
        $this->validator = $validator;
        $this->securityContext = $securityContext;
        $this->parser = $parser;
        $this->request = $request;
    }

    /**
     * @param array $fields
     * @param array $relationships
     * @return ResourceEntityInterface
     * @throws EntityConflictExceptionInterface
     * @throws ValidationExceptionInterface
     */
    public function create(array $fields, array $relationships = [])
    {
        $params = $this->parser->parse($this->request);
        $class = new \ReflectionClass($params->primaryClass);
        $entity = $class->newInstance();

        if ($class->implementsInterface(self::AUTHOR_IS_OWNER)) {
            $entity->setOwner($this->securityContext->getToken()->getUser());
        }

        foreach ($fields as $field => $value) {
            $method = self::SET . Inflector::camelize($field);

            if ($class->hasMethod($method)) $entity->$method($value);
        }

        foreach ($relationships as $relationship => $value) {
            $camelCased = Inflector::camelize($relationship);

            if (is_array($value)) {
                $getter = self::GET . $camelCased;
                $adder = self::ADD . Inflector::singularize($camelCased);

                foreach ($value as $item) $entity->$adder($item);
            } else {
                $method = self::SET . $camelCased;

                if ($class->hasMethod($method)) $entity->$method($value);
            }
        }

        $errors = $this->validator->validate($entity);

        if (0 < count($errors)) {
            throw new ValidationException($errors);
        }

        try {
            $this->em->persist($entity);
            $this->em->flush();
        } catch (ORMException $e) {
            throw new PersistenceException(self::ERROR_COULD_NOT_CREATE);
        }

        return $entity;
    }
}

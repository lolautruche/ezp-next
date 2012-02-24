<?php
namespace eZ\Publish\Core\Repository;

use eZ\Publish\Core\Repository\Values\User\PolicyUpdateStruct,
    eZ\Publish\API\Repository\Values\User\PolicyUpdateStruct as APIPolicyUpdateStruct,
    eZ\Publish\Core\Repository\Values\User\Policy,
    eZ\Publish\API\Repository\Values\User\Policy as APIPolicy,
    eZ\Publish\API\Repository\Values\User\RoleUpdateStruct,
    eZ\Publish\Core\Repository\Values\User\PolicyCreateStruct,
    eZ\Publish\API\Repository\Values\User\PolicyCreateStruct as APIPolicyCreateStruct,
    eZ\Publish\Core\Repository\Values\User\Role,
    eZ\Publish\API\Repository\Values\User\Role as APIRole,
    eZ\Publish\Core\Repository\Values\User\RoleCreateStruct,
    eZ\Publish\API\Repository\Values\User\RoleCreateStruct as APIRoleCreateStruct,
    eZ\Publish\API\Repository\Values\User\RoleAssignment,
    eZ\Publish\Core\Repository\Values\User\UserRoleAssignment,
    eZ\Publish\Core\Repository\Values\User\UserGroupRoleAssignment,
    eZ\Publish\API\Repository\Values\User\Limitation\RoleLimitation,
    eZ\Publish\API\Repository\Values\User\User,
    eZ\Publish\API\Repository\Values\User\UserGroup,
    eZ\Publish\API\Repository\Values\User\Limitation,

    eZ\Publish\SPI\Persistence\User\Policy as SPIPolicy,
    eZ\Publish\SPI\Persistence\User\Role as SPIRole,
    eZ\Publish\SPI\Persistence\User\RoleUpdateStruct as SPIRoleUpdateStruct,

    eZ\Publish\API\Repository\RoleService as RoleServiceInterface,
    eZ\Publish\API\Repository\Repository as RepositoryInterface,
    eZ\Publish\SPI\Persistence\Handler,

    ezp\Base\Exception\NotFound,
    eZ\Publish\Core\Base\Exceptions\InvalidArgumentValue,
    eZ\Publish\Core\Base\Exceptions\InvalidArgumentException,
    eZ\Publish\Core\Base\Exceptions\IllegalArgumentException,
    eZ\Publish\Core\Base\Exceptions\NotFoundException;

/**
 * This service provides methods for managing Roles and Policies
 *
 * @todo add get roles for user including limitations
 *
 * @package eZ\Publish\Core\Repository
 */
class RoleService implements RoleServiceInterface
{
    /**
     * @var \eZ\Publish\API\Repository\Repository
     */
    protected $repository;

    /**
     * @var \eZ\Publish\SPI\Persistence\Handler
     */
    protected $persistenceHandler;

    /**
     * Setups service with reference to repository object that created it & corresponding handler
     *
     * @param \eZ\Publish\API\Repository\Repository  $repository
     * @param \eZ\Publish\SPI\Persistence\Handler $handler
     */
    public function __construct( RepositoryInterface $repository, Handler $handler )
    {
        $this->repository = $repository;
        $this->persistenceHandler = $handler;
    }

    /**
     * Creates a new Role
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException if the authenticated user is not allowed to create a role
     * @throws \eZ\Publish\API\Repository\Exceptions\IllegalArgumentException if the name of the role already exists
     *
     * @param \eZ\Publish\API\Repository\Values\User\RoleCreateStruct $roleCreateStruct
     *
     * @return \eZ\Publish\API\Repository\Values\User\Role
     */
    public function createRole( APIRoleCreateStruct $roleCreateStruct )
    {
        if ( empty( $roleCreateStruct->identifier ) )
            throw new InvalidArgumentValue( "identifier", $roleCreateStruct->identifier, "RoleCreateStruct" );

        try
        {
            $existingRole = $this->loadRoleByIdentifier( $roleCreateStruct->identifier );
            if ( $existingRole !== null )
                throw new IllegalArgumentException( "identifier", $roleCreateStruct->identifier );
        }
        catch ( NotFoundException $e ) {}

        $spiRole = $this->buildPersistenceRoleObject( $roleCreateStruct );
        $createdRole = $this->persistenceHandler->userHandler()->createRole( $spiRole );

        return $this->buildDomainRoleObject( $createdRole );
    }

    /**
     * Updates the name and (5.x) description of the role
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException if the authenticated user is not allowed to update a role
     * @throws \eZ\Publish\API\Repository\Exceptions\IllegalArgumentException if the name of the role already exists
     *
     * @param \eZ\Publish\API\Repository\Values\User\Role $role
     * @param \eZ\Publish\API\Repository\Values\User\RoleUpdateStruct $roleUpdateStruct
     *
     * @return \eZ\Publish\API\Repository\Values\User\Role
     */
    public function updateRole( APIRole $role, RoleUpdateStruct $roleUpdateStruct )
    {
        if ( empty( $role->id ) )
            throw new InvalidArgumentValue( "id", $role->id, "Role" );

        if ( !empty( $roleUpdateStruct->identifier ) )
        {
            try
            {
                $existingRole = $this->loadRoleByIdentifier( $roleUpdateStruct->identifier );
                if ( $existingRole !== null )
                    throw new IllegalArgumentException( "identifier", $roleUpdateStruct->identifier );
            }
            catch ( NotFoundException $e ) {}
        }

        $loadedRole = $this->loadRole( $role->id );

        $spiRoleUpdateStruct = new SPIRoleUpdateStruct();
        $spiRoleUpdateStruct->id = $loadedRole->id;
        $spiRoleUpdateStruct->identifier = $roleUpdateStruct->identifier !== null ? $roleUpdateStruct->identifier : $role->identifier;
        $spiRoleUpdateStruct->name = $roleUpdateStruct->names !== null ? $roleUpdateStruct->names : $role->getNames();
        $spiRoleUpdateStruct->description = $roleUpdateStruct->descriptions !== null ? $roleUpdateStruct->descriptions : $role->getDescriptions();

        $this->persistenceHandler->userHandler()->updateRole( $spiRoleUpdateStruct );

        return $this->loadRole( $role->id );
    }

    /**
     * adds a new policy to the role
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException if the authenticated user is not allowed to add  a policy
     *
     * @param \eZ\Publish\API\Repository\Values\User\Role $role
     * @param \eZ\Publish\API\Repository\Values\User\PolicyCreateStruct $policyCreateStruct
     *
     * @return \eZ\Publish\API\Repository\Values\User\Role
     */
    public function addPolicy( APIRole $role, APIPolicyCreateStruct $policyCreateStruct )
    {
        if ( empty( $role->id ) )
            throw new InvalidArgumentValue( "id", $role->id, "Role" );

        if ( empty( $policyCreateStruct->module ) )
            throw new InvalidArgumentValue( "module", $policyCreateStruct->module, "PolicyCreateStruct" );

        if ( empty( $policyCreateStruct->function ) )
            throw new InvalidArgumentValue( "function", $policyCreateStruct->function, "PolicyCreateStruct" );

        if ( $policyCreateStruct->module === '*' && $policyCreateStruct->function !== '*' )
            throw new InvalidArgumentValue( "module", $policyCreateStruct->module, "PolicyCreateStruct" );

        // load role to check existence
        $this->loadRole( $role->id );

        $spiPolicy = $this->buildPersistencePolicyObject( $policyCreateStruct->module,
                                                          $policyCreateStruct->function,
                                                          $policyCreateStruct->getLimitations() );

        $this->persistenceHandler->userHandler()->addPolicy( $role->id, $spiPolicy );

        return $this->loadRole( $role->id );
    }

    /**
     * removes a policy from the role
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException if the authenticated user is not allowed to remove a policy
     *
     * @param \eZ\Publish\API\Repository\Values\User\Role $role
     * @param \eZ\Publish\API\Repository\Values\User\Policy $policy the policy to remove from the role
     *
     * @return \eZ\Publish\API\Repository\Values\User\Role the updated role
     */
    public function removePolicy( APIRole $role, APIPolicy $policy )
    {
        if ( empty( $role->id ) )
            throw new InvalidArgumentValue( "id", $role->id, "Role" );

        if ( empty( $policy->id ) )
            throw new InvalidArgumentValue( "id", $policy->id, "Policy" );

        // load role to check existence
        $this->loadRole( $role->id );

        $this->persistenceHandler->userHandler()->removePolicy( $role->id, $policy->id );

        return $this->loadRole( $role->id );
    }

    /**
     * Updates the limitations of a policy. The module and function cannot be changed and
     * the limitations are replaced by the ones in $roleUpdateStruct
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException if the authenticated user is not allowed to update a policy
     *
     * @param \eZ\Publish\API\Repository\Values\User\PolicyUpdateStruct $policyUpdateStruct
     * @param \eZ\Publish\API\Repository\Values\User\Policy $policy
     *
     * @return \eZ\Publish\API\Repository\Values\User\Policy
     */
    public function updatePolicy( APIPolicy $policy, APIPolicyUpdateStruct $policyUpdateStruct )
    {
        if ( empty( $policy->id ) )
            throw new InvalidArgumentValue( "id", $policy->id, "Policy" );

        if ( empty( $policy->roleId ) )
            throw new InvalidArgumentValue( "roleId", $policy->roleId, "Policy" );

        if ( empty( $policy->module ) )
            throw new InvalidArgumentValue( "module", $policy->module, "Policy" );

        if ( empty( $policy->function ) )
            throw new InvalidArgumentValue( "function", $policy->function, "Policy" );

        $spiPolicy = $this->buildPersistencePolicyObject( $policy->module, $policy->function, $policyUpdateStruct->getLimitations() );
        $spiPolicy->id = $policy->id;
        $spiPolicy->roleId = $policy->roleId;

        $this->persistenceHandler->userHandler()->updatePolicy( $spiPolicy );

        return $this->buildDomainPolicyObject( $spiPolicy );
    }

    /**
     * loads a role for the given id
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException if the authenticated user is not allowed to read this role
     * @throws \eZ\Publish\API\Repository\Exceptions\NotFoundException if a role with the given id was not found
     *
     * @param mixed $id
     *
     * @return \eZ\Publish\API\Repository\Values\User\Role
    */
    public function loadRole( $id )
    {
        if ( empty( $id ) )
            throw new InvalidArgumentValue( "id", $id );

        try
        {
            $spiRole = $this->persistenceHandler->userHandler()->loadRole( $id );
        }
        catch( NotFound $e )
        {
            throw new NotFoundException( "role", $id, $e );
        }

        return $this->buildDomainRoleObject( $spiRole );
    }

    /**
     * loads a role for the given identifier
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException if the authenticated user is not allowed to read this role
     * @throws \eZ\Publish\API\Repository\Exceptions\NotFoundException if a role with the given name was not found
     *
     * @param string $identifier
     *
     * @return \eZ\Publish\API\Repository\Values\User\Role
     */
    public function loadRoleByIdentifier( $identifier )
    {
        if ( empty( $identifier ) )
            throw new InvalidArgumentValue( "identifier", $identifier );

        try
        {
            $spiRole = $this->persistenceHandler->userHandler()->loadRoleByIdentifier( $identifier );
        }
        catch( NotFound $e )
        {
            throw new NotFoundException( "role", $identifier, $e );
        }

        return $this->buildDomainRoleObject( $spiRole );
    }

    /**
     * loads all roles
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException if the authenticated user is not allowed to read the roles
     *
     * @return array an array of {@link \eZ\Publish\API\Repository\Values\User\Role}
     */
    public function loadRoles()
    {
        $spiRoles = $this->persistenceHandler->userHandler()->loadRoles();

        if ( !is_array( $spiRoles ) || empty( $spiRoles ) )
            return array();

        $rolesToReturn = array();
        foreach ( $spiRoles as $spiRole )
        {
            $rolesToReturn[] = $this->buildDomainRoleObject( $spiRole );
        }

        return $rolesToReturn;
    }

    /**
     * deletes the given role
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException if the authenticated user is not allowed to delete this role
     *
     * @param \eZ\Publish\API\Repository\Values\User\Role $role
     */
    public function deleteRole( APIRole $role )
    {
        if ( empty( $role->id ) )
            throw new InvalidArgumentValue( "id", $role->id, "Role" );

        // load role to check existence
        $this->loadRole( $role->id );

        $this->persistenceHandler->userHandler()->deleteRole( $role->id );
    }

    /**
     * loads all policies from roles which are assigned to a user or to user groups to which the user belongs
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\NotFoundException if a user with the given id was not found
     *
     * @param $userId
     *
     * @return array an array of {@link Policy}
     */
    public function loadPoliciesByUserId( $userId )
    {
        if ( empty( $userId ) )
            throw new InvalidArgumentValue( "userId", $userId );

        // load user to verify existence, throws \eZ\Publish\API\Repository\Exceptions\NotFoundException
        $user = $this->repository->getUserService()->loadUser( $userId );
        $spiPolicies = $this->persistenceHandler->userHandler()->loadPoliciesByUserId( $user->id );

        $policies = array();
        if ( is_array( $spiPolicies ) && !empty( $spiPolicies ) )
        {
            foreach ( $spiPolicies as $spiPolicy )
            {
                $policies[] = $this->buildDomainPolicyObject( $spiPolicy );
            }
        }

        return $policies;
    }

    /**
     * assigns a role to the given user group
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException if the authenticated user is not allowed to assign a role
     *
     * @param \eZ\Publish\API\Repository\Values\User\Role $role
     * @param \eZ\Publish\API\Repository\Values\User\UserGroup $userGroup
     * @param \eZ\Publish\API\Repository\Values\User\Limitation\RoleLimitation $roleLimitation an optional role limitation (which is either a subtree limitation or section limitation)
     */
    public function assignRoleToUserGroup( APIRole $role, UserGroup $userGroup, RoleLimitation $roleLimitation = null )
    {
        if ( empty( $role->id ) )
            throw new InvalidArgumentValue( "id", $role->id, "Role" );

        if ( empty( $userGroup->id ) )
            throw new InvalidArgumentValue( "id", $userGroup->id, "UserGroup" );

        $spiRoleLimitation = null;
        if ( $roleLimitation !== null )
        {
            $limitationIdentifier = $roleLimitation->getIdentifier();
            if ( $limitationIdentifier !== Limitation::SUBTREE && $limitationIdentifier !== Limitation::SECTION )
                throw new InvalidArgumentValue( "identifier", $limitationIdentifier, "RoleLimitation" );

            $spiRoleLimitation = array( $limitationIdentifier => $roleLimitation->limitationValues );
        }

        $this->persistenceHandler->userHandler()->assignRole( $userGroup->id, $role->id, $spiRoleLimitation );
    }

    /**
     * removes a role from the given user group.
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException if the authenticated user is not allowed to remove a role
     * @throws \eZ\Publish\API\Repository\Exceptions\InvalidArgumentException  If the role is not assigned to the given user group
     *
     * @param \eZ\Publish\API\Repository\Values\User\Role $role
     * @param \eZ\Publish\API\Repository\Values\User\UserGroup $userGroup
     */
    public function unassignRoleFromUserGroup( APIRole $role, UserGroup $userGroup )
    {
        if ( empty( $role->id ) )
            throw new InvalidArgumentValue( "id", $role->id, "Role" );

        if ( empty( $userGroup->id ) )
            throw new InvalidArgumentValue( "id", $userGroup->id, "UserGroup" );

        try
        {
            $spiRole = $this->persistenceHandler->userHandler()->loadRole( $role->id );
        }
        catch( NotFound $e )
        {
            throw new NotFoundException( "role", $role->id, $e );
        }

        if ( !in_array( $userGroup->id, $spiRole->groupIds ) )
            throw new InvalidArgumentException( "\$userGroup->id", "Role is not assigned to the user group" );

        $this->persistenceHandler->userHandler()->unAssignRole( $userGroup->id, $role->id );
    }

    /**
     * assigns a role to the given user
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException if the authenticated user is not allowed to assign a role
     *
     * @todo add limitations
     *
     * @param \eZ\Publish\API\Repository\Values\User\Role $role
     * @param \eZ\Publish\API\Repository\Values\User\User $user
     * @param \eZ\Publish\API\Repository\Values\User\Limitation\RoleLimitation $roleLimitation an optional role limitation (which is either a subtree limitation or section limitation)
     */
    public function assignRoleToUser( APIRole $role, User $user, RoleLimitation $roleLimitation = null )
    {
        if ( empty( $role->id ) )
            throw new InvalidArgumentValue( "id", $role->id, "Role" );

        if ( empty( $user->id ) )
            throw new InvalidArgumentValue( "id", $user->id, "User" );

        $spiRoleLimitation = null;
        if ( $roleLimitation !== null )
        {
            $limitationIdentifier = $roleLimitation->getIdentifier();
            if ( $limitationIdentifier !== Limitation::SUBTREE && $limitationIdentifier !== Limitation::SECTION )
                throw new InvalidArgumentValue( "identifier", $limitationIdentifier, "RoleLimitation" );

            $spiRoleLimitation = array( $limitationIdentifier => $roleLimitation->limitationValues );
        }

        $this->persistenceHandler->userHandler()->assignRole( $user->id, $role->id, $spiRoleLimitation );
    }

    /**
     * removes a role from the given user.
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException if the authenticated user is not allowed to remove a role
     * @throws \eZ\Publish\API\Repository\Exceptions\InvalidArgumentException If the role is not assigned to the user
     *
     * @param \eZ\Publish\API\Repository\Values\User\Role $role
     * @param \eZ\Publish\API\Repository\Values\User\User $user
     */
    public function unassignRoleFromUser( APIRole $role, User $user )
    {
        if ( empty( $role->id ) )
            throw new InvalidArgumentValue( "id", $role->id, "Role" );

        if ( empty( $user->id ) )
            throw new InvalidArgumentValue( "id", $user->id, "User" );

        try
        {
            $spiRole = $this->persistenceHandler->userHandler()->loadRole( $role->id );
        }
        catch( NotFound $e )
        {
            throw new NotFoundException( "role", $role->id, $e );
        }

        if ( !in_array( $user->id, $spiRole->groupIds ) )
            throw new InvalidArgumentException( "\$user->id", "Role is not assigned to the user" );

        $this->persistenceHandler->userHandler()->unAssignRole( $user->id, $role->id );
    }

    /**
     * returns the assigned user and user groups to this role
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException if the authenticated user is not allowed to read a role
     *
     * @param \eZ\Publish\API\Repository\Values\User\Role $role
     *
     * @return array an array of {@link RoleAssignment}
     */
    public function getRoleAssignments( APIRole $role )
    {
        if ( empty( $role->id ) )
            throw new InvalidArgumentValue( "id", $role->id, "Role" );

        try
        {
            $spiRole = $this->persistenceHandler->userHandler()->loadRole( $role->id );
        }
        catch( NotFound $e )
        {
            throw new NotFoundException( "role", $role->id, $e );
        }

        $roleAssignments = array();
        if ( is_array( $spiRole->groupIds ) && !empty( $spiRole->groupIds ) )
        {
            foreach ( $spiRole->groupIds as $groupId )
            {
                // $spiRole->groupIds can contain both group and user IDs, although assigning roles to
                // users is deprecated. Hence, we'll first check for groups. If that fails,
                // we'll check for users
                $userGroup = $this->repository->getUserService()->loadUserGroup( $groupId );
                if ( $userGroup !== null )
                {
                    $roleAssignments[] = new UserGroupRoleAssignment( array(
                        // @todo: add limitation
                        'limitation' => null,
                        'role'       => $this->buildDomainRoleObject( $spiRole ),
                        'userGroup'  => $userGroup
                    ) );
                }
                else
                {
                    $user = $this->repository->getUserService()->loadUser( $groupId );
                    if ( $user !== null )
                    {
                        $roleAssignments[] = new UserRoleAssignment( array(
                            // @todo: add limitation
                            'limitation' => null,
                            'role'       => $this->buildDomainRoleObject( $spiRole ),
                            'user'       => $user
                        ) );
                    }
                }
            }
        }

        return $roleAssignments;
    }

    /**
     * returns the roles assigned to the given user
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException if the authenticated user is not allowed to read a user
     *
     * @param \eZ\Publish\API\Repository\Values\User\User $user
     *
     * @return array an array of {@link UserRoleAssignment}
     */
    public function getRoleAssignmentsForUser( User $user )
    {
        if ( empty( $user->id ) )
            throw new InvalidArgumentValue( "id", $user->id, "User" );

        $roleAssignments = array();

        $spiRoles = $this->persistenceHandler->userHandler()->loadRolesByGroupId( $user->id );
        if ( is_array( $spiRoles ) && !empty( $spiRoles ) )
        {
            foreach ( $spiRoles as $spiRole )
            {
                $roleAssignments[] = new UserRoleAssignment( array(
                    // @todo: add limitation
                    'limitation' => null,
                    'role'       => $this->buildDomainRoleObject( $spiRole ),
                    'user'       => $user
                ) );
            }
        }

        return $roleAssignments;
    }

    /**
     * returns the roles assigned to the given user group
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException if the authenticated user is not allowed to read a user group
     *
     * @param \eZ\Publish\API\Repository\Values\User\UserGroup $userGroup
     *
     * @return array an array of {@link UserGroupRoleAssignment}
     */
    public function getRoleAssignmentsForUserGroup( UserGroup $userGroup )
    {
        if ( empty( $userGroup->id ) )
            throw new InvalidArgumentValue( "id", $userGroup->id, "UserGroup" );

        $roleAssignments = array();

        $spiRoles = $this->persistenceHandler->userHandler()->loadRolesByGroupId( $userGroup->id );
        if ( is_array( $spiRoles ) && !empty( $spiRoles ) )
        {
            foreach ( $spiRoles as $spiRole )
            {
                $roleAssignments[] = new UserGroupRoleAssignment( array(
                    // @todo: add limitation
                    'limitation' => null,
                    'role'       => $this->buildDomainRoleObject( $spiRole ),
                    'userGroup'  => $userGroup
                ) );
            }
        }

        return $roleAssignments;
    }

    /**
     * instantiates a role create class
     *
     * @param string $name
     *
     * @return \eZ\Publish\API\Repository\Values\User\RoleCreateStruct
     */
    public function newRoleCreateStruct( $name )
    {
        return new RoleCreateStruct( array(
            'identifier'   => $name,
            'names'        => array(),
            'descriptions' => array(),
            'policies'     => array()
        ) );
    }

    /**
     * instantiates a policy create class
     *
     * @param string $module
     * @param string $function
     *
     * @return \eZ\Publish\API\Repository\Values\User\PolicyCreateStruct
     */
    public function newPolicyCreateStruct( $module, $function )
    {
        return new PolicyCreateStruct( array(
            'module'      => $module,
            'function'    => $function,
            'limitations' => array()
        ) );
    }

    /**
     * instantiates a policy update class
     *
     * @return \eZ\Publish\API\Repository\Values\User\PolicyUpdateStruct
     */
    public function newPolicyUpdateStruct()
    {
        return new PolicyUpdateStruct( array(
            'limitations' => array()
        ) );
    }

    /**
     * instantiates a policy update class
     *
     * @return \eZ\Publish\API\Repository\Values\User\RoleUpdateStruct
     */
    public function newRoleUpdateStruct()
    {
        return new RoleUpdateStruct();
    }

    /**
     * Maps provided SPI Role value object to API Role value object
     *
     * @param \eZ\Publish\SPI\Persistence\User\Role $role
     *
     * @return \eZ\Publish\API\Repository\Values\User\Role
     */
    protected function buildDomainRoleObject( SPIRole $role )
    {
        $rolePolicies = array();
        foreach ( $role->policies as $spiPolicy )
        {
            $rolePolicies[] = $this->buildDomainPolicyObject( $spiPolicy );
        }

        return new Role( array(
            'id'               => $role->id,
            'identifier'       => $role->identifier,
            //@todo: add main language code
            'mainLanguageCode' => null,
            'names'            => $role->name,
            'descriptions'     => $role->description,
            'policies'         => $rolePolicies
        ) );
    }

    /**
     * Maps provided SPI Policy value object to API Policy value object
     *
     * @param \eZ\Publish\SPI\Persistence\User\Policy $policy
     *
     * @return \eZ\Publish\API\Repository\Values\User\Policy
     */
    protected function buildDomainPolicyObject( SPIPolicy $policy )
    {
        $policyLimitations = '*';
        if ( $policy->module !== '*' && $policy->function !== '*'
             && is_array( $policy->limitations ) && !empty( $policy->limitations ) )
        {
            $policyLimitations = array();
            foreach ( $policy->limitations as $limitationIdentifier => $limitationValues )
            {
                $limitation = $this->getLimitationFromIdentifier( $limitationIdentifier );
                $limitation->limitationValues = $limitationValues;
                $policyLimitations[] = $limitation;
            }
        }

        return new Policy( array(
            'id'          => $policy->id,
            'roleId'      => $policy->roleId,
            'module'      => $policy->module,
            'function'    => $policy->function,
            'limitations' => $policyLimitations
        ) );
    }

    /**
     * Returns the correct implementation of API Limitation value object
     * based on provided identifier
     *
     * @param string $identifier
     *
     * @return \eZ\Publish\API\Repository\Values\User\Limitation
     */
    protected function getLimitationFromIdentifier( $identifier )
    {
        switch( $identifier )
        {
            case Limitation::CONTENTTYPE :
                return new \eZ\Publish\API\Repository\Values\User\Limitation\ContentTypeLimitation();
                break;

            case Limitation::LANGUAGE :
                return new \eZ\Publish\API\Repository\Values\User\Limitation\LanguageLimitation();
                break;

            case Limitation::LOCATION :
                return new \eZ\Publish\API\Repository\Values\User\Limitation\LocationLimitation();
                break;

            case Limitation::OWNER :
                return new \eZ\Publish\API\Repository\Values\User\Limitation\OwnerLimitation();
                break;

            case Limitation::PARENTOWNER :
                return new \eZ\Publish\API\Repository\Values\User\Limitation\ParentOwnerLimitation();
                break;

            case Limitation::PARENTCONTENTTYPE :
                return new \eZ\Publish\API\Repository\Values\User\Limitation\ParentContentTypeLimitation();
                break;

            case Limitation::PARENTDEPTH :
                return new \eZ\Publish\API\Repository\Values\User\Limitation\ParentDepthLimitation();
                break;

            case Limitation::SECTION :
                return new \eZ\Publish\API\Repository\Values\User\Limitation\SectionLimitation();
                break;

            case Limitation::SITEACCESS :
                return new \eZ\Publish\API\Repository\Values\User\Limitation\SiteaccessLimitation();
                break;

            case Limitation::STATE :
                return new \eZ\Publish\API\Repository\Values\User\Limitation\StateLimitation();
                break;

            case Limitation::SUBTREE :
                return new \eZ\Publish\API\Repository\Values\User\Limitation\SubtreeLimitation();
                break;

            case Limitation::USERGROUP :
                return new \eZ\Publish\API\Repository\Values\User\Limitation\UserGroupLimitation();
                break;

            case Limitation::PARENTUSERGROUP :
                return new \eZ\Publish\API\Repository\Values\User\Limitation\ParentUserGroupLimitation();
                break;

            default:
                return new \eZ\Publish\API\Repository\Values\User\Limitation\CustomLimitation( $identifier );
        }
    }

    /**
     * Creates SPI Role value object from provided API role create struct
     *
     * @param \eZ\Publish\API\Repository\Values\User\RoleCreateStruct $roleCreateStruct
     *
     * @return \eZ\Publish\SPI\Persistence\User\Role
     */
    protected function buildPersistenceRoleObject( APIRoleCreateStruct $roleCreateStruct )
    {
        $policiesToCreate = array();
        $policyCreateStructs = $roleCreateStruct->getPolicies();
        if ( !empty( $policyCreateStructs ) )
        {
            foreach ( $policyCreateStructs as $policyCreateStruct )
            {
                $policiesToCreate[] = $this->buildPersistencePolicyObject( $policyCreateStruct->module,
                                                                           $policyCreateStruct->function,
                                                                           $policyCreateStruct->getLimitations() );
            }
        }

        return new SPIRole( array(
            'identifier'  => $roleCreateStruct->identifier,
            //@todo: main language code ?
            'name'        => $roleCreateStruct->names,
            'description' => $roleCreateStruct->descriptions,
            'policies'    => $policiesToCreate
        ) );
    }

    /**
     * Creates SPI Policy value object from provided module, function and limitations
     *
     * @param string $module
     * @param string $function
     * @param \eZ\Publish\API\Repository\Values\User\Limitation[] $limitations
     *
     * @return \eZ\Publish\SPI\Persistence\User\Policy
     */
    protected function buildPersistencePolicyObject( $module, $function, array $limitations )
    {
        $limitationsToCreate = '*';
        if ( $module !== '*' && $function !== '*' && is_array( $limitations ) && !empty( $limitations ) )
        {
            $limitationsToCreate = array();
            foreach ( $limitations as $limitation )
            {
                $limitationsToCreate[$limitation->getIdentifier()] = $limitation->limitationValues;
            }
        }

        return new SPIPolicy( array(
            'module'      => $module,
            'function'    => $function,
            'limitations' => $limitationsToCreate
        ) );
    }
}

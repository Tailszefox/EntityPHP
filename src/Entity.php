<?php

namespace EntityPHP;

//Interface in order to force definition of method __structure()
interface iEntity
{
	public static function __structure();
}

//All classes inherited from this class can be bind to a table from DB
abstract class Entity implements iEntity
{
	protected	static	$table_name	=	null;
	protected	static	$id_name	=	'id';

	public function __construct(Array $props = array())
	{
		$this->setProps($props);
	}

	/**
	 * Prepare the data of the given Entity object for an INSERT or UPDATE SQL request
	 * @access private
	 * @param bool $update Do we have to prepare an UPDATE request?
	 * @return string SQL representation of the given value
	 * @throws \Exception
	 */
	final private function prepareDataForSQL($update = false)
	{
		$fields		=	static::__structure();
		$tableName	=	static::getTableName();

		$fieldsSQL	=	array();
		$valuesSQL	=	array();
		$foreignSQL	=	array();

		//Iterate through each properties of the class
		foreach($fields as $field => $sql_type)
		{
			$php_type	=	Core::getPHPType($sql_type);

			switch($php_type)
			{
				case Core::TYPE_CLASS:
					$className	=	$sql_type;

					if( ! class_exists($className))
						throw new \Exception('The field "'.$field.'" is defined as an instance of "'.$className.'" but this class does not exist.');

					if( ! $update && ! ($this->$field instanceof $className))
						$this->load($field);

					if(empty($this->$field))
					{
						$fieldsSQL[]	=	'id_'.$field;
						$valuesSQL[]	=	'null';
						continue;
					}

					if($this->$field instanceof $className)
					{
						$other_id	=	$this->$field->getId();
						if($other_id > -1)
						{
							$fieldsSQL[]	=	'id_'.$field;
							$valuesSQL[]	=	$other_id;
						}
						else
							throw new \Exception('The field "'.$field.'" has to be an instance already saved in the DB.');
					}
					else
						throw new \Exception('The field "'.$field.'" is not an instance of "'.$className.'".');

					break;

				case Core::TYPE_ASSOC_ARRAY: //List of foreign keys with added properties
					// We don't update those here
					break;

				case Core::TYPE_ARRAY: //List of foreign keys
					$foreignClass	=	current($sql_type);

					if($update)
						$newId	=	$this->getId();
					else
					{
						$query		=	Core::$current_db->query('SHOW TABLE STATUS LIKE "'.$tableName.'"');
						$data		=	$query->fetch(\PDO::FETCH_ASSOC);
						$newId		=	$data['Auto_increment'];
					}

					$temp	=	array();

					if(count($this->$field) === 0 && ! $update)
						$this->load($field);

					if(empty($this->$field))
						$array	=	array();
					else
						$array = is_array($this->$field) ? $this->$field : $this->$field->getArray();

					foreach($array as $key => $obj)
					{
						if($obj->existsInDB())
							$temp[]	=	'('.$newId.','.$obj->getId().')';
						else
						{
							$tempArray	=	$obj->$field; //We have to use a temp variable... :(
							unset($tempArray[$key]);
						}
					}

					if( ! $this->$field instanceof EntityArray)
						$this->$field	=	new EntityArray($foreignClass, $this->$field);

					if($update)
						$foreignSQL[]	=	'DELETE FROM '.$tableName.'2'.$field.' WHERE id_'.$tableName.'='.$newId;

					if( ! empty($temp))
						$foreignSQL[]	=	'INSERT INTO '.$tableName.'2'.$field.' (id_'.$tableName.',id_'.$field.') VALUES '.implode(',', $temp);

					break;

				// Other types are treated by the core
				default:
					$fieldsSQL[] = $field;
					$valuesSQL[] = Core::convertValueForSql($php_type, $this->$field);
					break;
			}
		}

		return array('fields' => $fieldsSQL, 'values' => $valuesSQL, 'foreign' => $foreignSQL);
	}

	/**
	 * Get the name of the primary id field of the Entity which calls this methods
	 * @access public
	 * @static
	 * @return string Field name
	 * @throws \Exception
	 */
	public static function getIdName()
	{
		return static::$id_name;
	}

	/**
	 * Get the SQL table name of the Entity which calls this method
	 * @access public
	 * @static
	 * @return string Table name
	 * @throws \Exception
	 */
	public static function getTableName()
	{
		if( ! empty(static::$table_name))
			return static::$table_name;

		$calledClass	=	get_called_class();

		if($calledClass != 'EntityPHP\Entity')
		{
			$parentClass	=	get_parent_class($calledClass);

			//Prevision for the future when we'll handle Entity inheritance
			while($parentClass !== 'EntityPHP\Entity')
			{
				$calledClass	=	$parentClass;
				$parentClass	=	get_parent_class($calledClass);
			}

			//Handle namespaces
			$start	=	strrpos($calledClass, '\\');

			if($start === false)
				return $calledClass;

			return substr($calledClass, $start + 1);
		}
		throw new \Exception('Entity::getTableName() : only subclasses of Entity can call this method.');
	}

	/**
	 * Get the Entities this Entity depends on
	 * @access public
	 * @static
	 */
	final public static function getDependencies()
	{
		$className	=	get_called_class();

		//Prevision for the future when we'll handle Entity inheritance
		if(get_parent_class($className) === 'EntityPHP\Entity')
		{
			$fields         =   static::__structure();
			$dependencies   =   array();

			foreach($fields as $field)
			{
				// Check if the field is another Entity
				switch(Core::getPHPType($field))
				{
					case Core::TYPE_CLASS:
						$dependencies[] = $field;
						break;

					case Core::TYPE_ASSOC_ARRAY:
						$dependencies[] = key($field);
						break;

					case Core::TYPE_ARRAY:
						$dependencies[] = current($field);
						break;
				}
			}
			return array_unique($dependencies);
		}
		else
			throw new \Exception('Only direct subclasses of Entity can call "getDependencies()".');
	}

	/**
	 * Check if the SQL table corresponding to the Entity class exists
	 * @access public
	 * @static
	 */
	final public static function tableExists()
	{
		$className	=	get_called_class();

		//Prevision for the future when we'll handle Entity inheritance
		if(get_parent_class($className) === 'EntityPHP\Entity')
		{
			$tableName	=	$className::getTableName();

			$query	=	Core::$current_db->query('SELECT DISTINCT table_name FROM information_schema.statistics WHERE table_name = "' . $tableName . '"');
			return $query->rowCount() > 0;
		}
		else
			throw new \Exception('Only direct subclasses of Entity can call "tableExists()".');
	}

	/**
	 * Check if the junction table between this entity and another is present
	 * @access public
	 * @param string $field_name Field name linking to the other table
	 * @static
	 */
	final public static function junctionTableExists($field_name)
	{
		$className	=	get_called_class();

		//Prevision for the future when we'll handle Entity inheritance
		if(get_parent_class($className) === 'EntityPHP\Entity')
		{
			$tableName		=	$className::getTableName();

			$query	=	Core::$current_db->query('SELECT DISTINCT table_name FROM information_schema.statistics WHERE table_name = "'.$tableName.'2'.$field_name.'"');
			return $query->rowCount() > 0;
		}
		else
			throw new \Exception('Only direct subclasses of Entity can call "junctionTableExists()".');
	}

	/**
	 * Create the SQL table of the Entity class which calls this method
	 * @access public
	 * @static
	 */
	final public static function createTable()
	{
		$className	=	get_called_class();

		//Prevision for the future when we'll handle Entity inheritance
		if(get_parent_class($className) === 'EntityPHP\Entity')
		{
			$foreign_sql_reqs	=	array();
			$fields				=	static::__structure();
			$tableName			=	static::getTableName();
			$idName				=	static::getIdName();

			$sql				=	'CREATE TABLE '.$tableName.' ('.$idName.' INT(11) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,';
			$sql_foreign		=	'';

			foreach($fields as $field_name => $sql_type)
			{
				$php_type	=	Core::getPHPType($sql_type);

				switch($php_type)
				{
					case Core::TYPE_CLASS:
						//We can add a foreign key to this class
						$otherClassName	=	$sql_type;
						$sql			.=	'id_'.$field_name.' INT(11) UNSIGNED NULL DEFAULT NULL, ';
						$sql_foreign	.=	'ADD CONSTRAINT fk_'.$tableName.'_id_'.$field_name;
						$sql_foreign	.=	' FOREIGN KEY (id_'.$field_name.') REFERENCES '.$otherClassName::getTableName().'('.$otherClassName::getIdName().'), ';
						break;

					case Core::TYPE_ARRAY:
						$otherClassName		=	current($sql_type);
						$foreign_sql_reqs[]	=	Core::generateRequestForForeignFields($tableName, $otherClassName::getTableName(), $idName, $otherClassName::getIdName(), $field_name);
						break;

					case Core::TYPE_ASSOC_ARRAY:
						$otherClassName         =	key($sql_type);
						$supplementaryFields    =	current($sql_type);

						$foreign_sql_reqs[]     =	Core::generateRequestForForeignFields(
							$tableName, $otherClassName::getTableName(), $idName, $otherClassName::getIdName(), $field_name, $supplementaryFields);
						break;

					default:
						$sql	.=	$field_name.' '.$sql_type.', ';
						break;
				}
			}

			$foreign_sql_reqs[]	=	'ALTER TABLE '.$tableName.' '.substr($sql_foreign, 0, -2);

			$sql	=	substr($sql, 0, -2).') '.(Core::$current_db_is_utf8 ? 'DEFAULT CHARSET=utf8 ' : '').'ENGINE=InnoDB';

			$affected = Core::$current_db->exec($sql);
			if($affected === false)
			{
				$error = Core::$current_db->errorInfo();
				throw new \Exception('Error while creating table ' . $tableName . ': ' . $error[2]);
			}

			foreach($foreign_sql_reqs as $request)
				Core::$current_db->exec($request);
		}
		else
			throw new \Exception('Only direct subclasses of Entity can call "createTable()".');
	}

	/**
	 * Update the SQL table definition of the Entity class which calls this method
	 * @access public
	 * @static
	 */
	final public static function updateTable()
	{
		$className	=	get_called_class();

		//Prevision for the future when we'll handle Entity inheritance
		if(get_parent_class($className) === 'EntityPHP\Entity')
		{
			$classFields			=	static::__structure();
			$tableName				=	static::getTableName();
			$idName					=	static::getIdName();
			$tableFields			=	array();
			$tableFieldsClean		=	array();
			$change					=	false;
			$sql					=	'';
			$foreign_sql_reqs		=	array();

			if( ! static::tableExists())
			{
				throw new \Exception("The table for " . $className . " does not exist, please call createTable() instead.");
			}

			$query	=	Core::$current_db->query('SHOW COLUMNS FROM '.$tableName);
			$fields	=	$query->fetchAll(\PDO::FETCH_ASSOC);

			foreach($fields as $field)
			{
				if($field['Field'] !== 'id')
				{
					$is_id	=	false;
					//Test if the property is id_*
					if(substr($field['Field'], 0, 3) === 'id_')
					{
						$field['Field']	=	substr($field['Field'], 3);
						$is_id			=	true;
					}
					$tableFieldsClean[]	=	$field['Field'];

					/* If the class doesn't have a property
					having this name, we delete it */
					if( ! isset($classFields[$field['Field']]))
					{
						$sql	.=	'DROP '.($is_id ? 'id_' : '').$field['Field'].',';
						$change	=	true;

						/* Drop foreign key if exists */
						$query	=	Core::$current_db->query('SELECT NULL FROM information_schema.TABLE_CONSTRAINTS
															WHERE CONSTRAINT_SCHEMA = DATABASE()
															AND CONSTRAINT_NAME = "fk_'.$tableName.'_id_'.$field['Field'].'"');
						if($query->rowCount() > 0)
						{
							Core::$current_db->exec('ALTER TABLE '.$tableName.' DROP FOREIGN KEY fk_'.$tableName.'_id_'.$field['Field']);
							Core::$current_db->exec('DROP INDEX fk_'.$tableName.'_id_'.$field['Field'].' ON '.$tableName);
						}
					}
					else
						$tableFields[$field['Field']]	=	trim(strtoupper($field['Type']));
				}
			}

			/* $tableFields now contains all fields of
			the table according to the SQL database. */
			$sql_foreign	=	'';

			foreach($classFields as $field_name => $sql_type)
			{
				$php_type	=	Core::getPHPType($sql_type);

				// If the class property is not in the SQL definition, we'll add it
				// Arrays can't be part of the definition since they are defined as other tables
				if( ! isset($tableFields[$field_name]) && $php_type != Core::TYPE_ARRAY && $php_type != Core::TYPE_ASSOC_ARRAY)
				{
					switch($php_type)
					{
						case Core::TYPE_CLASS:
							$otherTableName	=	$sql_type::getTableName();
							$otherIdName	=	$sql_type::getIdName();

							$sql			.=	'ADD id_'.$field_name.' INT(11) UNSIGNED NULL DEFAULT NULL,';
							$sql_foreign	.=	'ADD CONSTRAINT fk_'.$tableName.'_id_'.$field_name.' FOREIGN KEY (id_'.$field_name.') REFERENCES '.$otherTableName.'('.$otherIdName.'), ';
							$change			=	true;

							break;
						default:
							$sql	.=	'ADD '.$field_name.' '.$sql_type.',';
							$change	=	true;
							break;
					}
				}
				// Field is an array; we check for the corresponding junction table
				else if($php_type == Core::TYPE_ARRAY || $php_type == Core::TYPE_ASSOC_ARRAY)
				{
					// Check if the table exists
					if(static::junctionTableExists($field_name))
					{
						$changeJunction	=	false;
						$sqlJunction	=	'';

						// Get the SQL structure
						$queryJunctionField 	=	Core::$current_db->query('SHOW COLUMNS FROM '.$tableName.'2'.$field_name);
						$junctionTableFields	=	array();

						$junctionClassFields = current($sql_type);

						// Get each field from SQL structure
						foreach($queryJunctionField->fetchAll(\PDO::FETCH_ASSOC) as $junctionField)
						{
							// Ignore ID fields since they can't change
							if(substr($junctionField['Field'], 0, 3) !== 'id_')
							{
								// Delete fields that are no longer part of the class definition
								if($php_type == Core::TYPE_ARRAY || ! isset($junctionClassFields[$junctionField['Field']]) )
								{
									$sqlJunction	.=	'DROP '.$junctionField['Field'].',';
									$changeJunction	=	true;
								}
								else
								{
									$junctionTableFields[$junctionField['Field']]	=	trim(strtoupper($junctionField['Type']));
								}
							}
						}

						// Check that each field exists and is the right type
						if($php_type == Core::TYPE_ASSOC_ARRAY)
						{
							foreach(current($sql_type) as $junctionClassFieldName => $junctionClassFieldType)
							{
								// The field does not exist, we'll add it
								if( ! isset($junctionTableFields[$junctionClassFieldName]))
								{
									$sqlJunction	.=	'ADD '.$junctionClassFieldName.' '.$junctionClassFieldType.',';
									$changeJunction	=	true;
								}
								// The field is of the wrong type, we'll change it
								else if(str_replace('"', '\'', strtoupper($junctionClassFieldType)) != str_replace('"', '\'', $junctionTableFields[$junctionClassFieldName]))
								{
									$sqlJunction	.=	'MODIFY '.$junctionClassFieldName.' '.$junctionClassFieldType.',';
									$changeJunction	=	true;
								}
							}
						}

						if($changeJunction)
							Core::$current_db->exec('ALTER TABLE '.$tableName.'2'.$field_name .' '.substr($sqlJunction, 0, -1));
					}
					// Junction table does not exist, we'll create it
					else
					{
						switch($php_type)
						{
							case Core::TYPE_ARRAY:
								$otherClassName 	=	current($sql_type);
								$otherTableName 	=	$otherClassName::getTableName();
								$otherIdName    	=	$otherClassName::getIdName();
								$foreign_sql_reqs[]	=	Core::generateRequestForForeignFields($tableName, $otherTableName, $idName, $otherIdName, $field_name);
								break;

							case Core::TYPE_ASSOC_ARRAY:
								$otherClassName         =	key($sql_type);
								$supplementaryFields    =	current($sql_type);
								$otherTableName         =	$otherClassName::getTableName();
								$otherIdName            =	$otherClassName::getIdName();
								$foreign_sql_reqs[]     =	Core::generateRequestForForeignFields(
									$tableName, $otherTableName, $idName, $otherIdName, $field_name, $supplementaryFields);

								break;
						}
					}
				}
				//Field is already present and is not an array: we check its SQL type!
				else if(str_replace('"', '\'', strtoupper($sql_type)) != str_replace('"', '\'', $tableFields[$field_name]))
				{
					switch($php_type)
					{
						case Core::TYPE_CLASS:
							$otherTableName	=	$sql_type::getTableName();
							$otherIdName	=	$sql_type::getIdName();

							$sql			.=	'MODIFY id_'.$field_name.' INT(11) UNSIGNED NULL DEFAULT NULL,';
							$sql_foreign	.=	'ADD CONSTRAINT fk_'.$tableName.'_id_'.$field_name.' FOREIGN KEY (id_'.$field_name.') REFERENCES '.$otherTableName.'('.$otherIdName.'), ';
							$change			=	true;

							break;
						default:
							$sql	.=	'MODIFY '.$field_name.' '.$sql_type.',';
							$change	=	true;
							break;
					}
				}
			}

			if( ! empty($sql_foreign))
				$foreign_sql_reqs[]	=	'ALTER TABLE '.$tableName.' '.substr($sql_foreign, 0, -2);

			if($change)
				Core::$current_db->exec('ALTER TABLE '.$tableName.' '.substr($sql, 0, -1));

			foreach($foreign_sql_reqs as $request)
				Core::$current_db->exec($request);
		}
		else
		{
			throw new \Exception('Only direct subclasses of Entity can call "updateTable()".');
		}
	}

	/**
	 * Delete the SQL table of the Entity class which calls this method
	 * @access public
	 * @static
	 */
	final public static function deleteTable()
	{
		$className	=	get_called_class();

		//Prevision for the future when we'll handle Entity inheritance
		if(get_parent_class($className) === 'EntityPHP\Entity')
		{
			$tableName	=	$className::getTableName();

			$query	=	Core::$current_db->query('SELECT DISTINCT table_name FROM information_schema.statistics WHERE index_name LIKE "$'.$tableName.'2%" OR index_name LIKE "%2'.$tableName.'" OR index_name LIKE "fk_'.$tableName.'_%"');

			if($query->rowCount() > 0)
				while($donnees = $query->fetch(\PDO::FETCH_NUM))
					Core::$current_db->exec('DROP TABLE '.$donnees[0]);

			Core::$current_db->exec('DROP TABLE '.$tableName);
		}
		else
			throw new \Exception('Only direct subclasses of Entity can call "deleteTable()".');
	}

	/**
	 * Return the id of the instance
	 * @access public
	 * @return int Id of the instance
	 */
	final public function getId()
	{
		$idName	=	static::getIdName();

		return isset($this->$idName) ? $this->$idName : -1;
	}

	/**
	 * Check if the instance is saved in the database
	 * @access public
	 * @return bool TRUE if the instance exists, FALSE otherwise
	 */
	final public function existsInDB()
	{
		$query	=	Core::$current_db->query('SELECT NULL FROM '.static::getTableName().' WHERE '.static::getIdName().'='.$this->getId());
		return $query->rowCount() > 0;
	}

	/**
	 * Check if the given id is used in the database
	 * @static
	 * @access public
	 * @param int $id The id to check
	 * @return bool TRUE if the id is used, FALSE otherwise
	 */
	final public static function idExistsInDB($id)
	{
		$query	=	Core::$current_db->query('SELECT NULL FROM '.static::getTableName().' WHERE '.static::getIdName().'='.intval($id));
		return $query->rowCount() > 0;
	}

	/**
	 * Indicates if two entities are the same one
	 * @access public
	 * @param Entity $other The Entity to check
	 * @return Bool True if the entities are the same one. False otherwise.
	 */
	final public function equals(Entity $other)
	{
		return get_class($this) === get_class($other) && $this->getId() === $other->getId();
	}

	/**
	 * Get an Entity from the called class by its id
	 * @static
	 * @access public
	 * @param int $id The id to check
	 * @return Entity|NULL The Entity with the given id if found. NULL otherwise.
	 * @throws \Exception
	 */
	final public static function getById($id)
	{
		if(get_called_class() !== 'EntityPHP\Entity')
		{
			return self::createRequest()
				->where(static::getIdName().'=?', array(intval($id)))
				->getOnly(1)
				->exec();
		}
		else
			throw new \Exception('Entity::getById(Int $id) -> Only a subclass of Entity can call this method.');
	}

	/**
	 * Get a list of Entities from the called class by their ids
	 * @static
	 * @access public
	 * @param Array(int) $ids The ids to check
	 * @return EntityArray An EntityArray containing the found instances
	 * @throws \Exception
	 */
	final public static function getByIds(Array $ids)
	{
		if(get_called_class() !== 'EntityPHP\Entity')
		{
			$where	=	null;

			foreach($ids as $id)
				$where	.= '?,';

			return self::createRequest()
				->where(static::getIdName().' IN ('.substr($where, 0, -1).')', $ids)
				->exec();
		}

		throw new \Exception('Entity::getByIds(Array(int) $ids) -> Only a subclass of Entity can call this method.');
	}

	/**
	 * Get all entities from the called class
	 * @static
	 * @access public
	 * @return EntityArray An EntityArray containing the found instances
	 * @throws \Exception
	 */
	final public static function getAll()
	{
		$entity	=	get_called_class();

		if($entity !== 'EntityPHP\Entity')
			return self::createRequest()->exec();

		throw new \Exception('Entity::getAll() -> Only a subclass of Entity can call this method.');
	}

	/**
	 * Create an EntityRequest for the called class
	 * @static
	 * @access public
	 * @param bool $useLeftJoins In case of joins, indicate if we want to use LEFT JOIN or simple JOIN
	 * @return EntityRequest The EntityRequest corresponding to this Entity class
	 */
	final public static function createRequest($useLeftJoins = false)
	{
		return new EntityRequest(get_called_class(), $useLeftJoins);
	}

	/**
	 * Simply a direct call to get_class_vars, to permit the public access
	 * @static
	 * @access public
	 * @return Array() The result of get_class_vars() for this class
	 */
	final public static function getVars()
	{
		$className	=	get_called_class();
		$vars		=	get_class_vars($className);
		$return		=	array();

		foreach($vars as $var => $value)
		{
			$prop	=	new \ReflectionProperty($className, $var);
			if( ! $prop->isStatic())
				$return[$var]=$value;
		}
		return $return;
	}

	/**
	 * Load given properties of the Entity
	 * @access public
	 * @param string $prop The property to load
	 * @param string $orderBy In case of Many 2 Many property, define how to order the collection
	 * @return mixed The loaded property
	 * @throws \Exception
	 */
	final public function load($prop, $orderBy = '')
	{
		$prop		=	trim($prop);
		$className	=	get_class($this);
		$fields		=	static::__structure();

		if(isset($fields[$prop]))
		{
			$php_type	=	Core::getPHPType($fields[$prop]);

			if	(	(is_string($fields[$prop]) && class_exists($fields[$prop])) || 
					($php_type === Core::TYPE_ARRAY && class_exists($fields[$prop][0])) ||
					($php_type === Core::TYPE_ASSOC_ARRAY && class_exists(key($fields[$prop])))
				)
			{
				//One to many
				if(is_string($fields[$prop]))
				{
					if(is_subclass_of($fields[$prop], 'EntityPHP\Entity'))
					{
						if(isset($this->{'id_'.$prop})) {
							$this->$prop	=	$fields[$prop]::getById($this->{'id_'.$prop});
							unset($this->{'id_'.$prop});
						}
					}
					else
						throw new \Exception('Entity::load(String $prop) : Property "'.$prop.'" from "'.$className.'" is not referencing to an Entity object.');
				}
				else //Many to Many
				{
					$request	=	static::createRequest()
										->select($prop)
										->where(static::getIdName().'=?', array($this->getId()));

					if( ! empty($orderBy))
						$request->orderBy($orderBy);

					$this->$prop	=	$request->exec();
				}

				return $this->$prop;
			}
			throw new \Exception('Entity::load(String $prop) : Property "'.$prop.'" from "'.$className.'" is not referencing to object(s).');
		}
		throw new \Exception('Entity::load(String $prop) : "'.$className.'" has no properties named "'.$prop.'".');
	}

	/**
	 * Generic setter/getter for all fields
	 * @access public
	 * @param string $prop The property to get/set
	 * @param mixed $value optional value to set the given property
	 * @return Entity/mixed The called Entity if $value is provided. Value of $prop  otherwise
	 */
	final public function prop($prop, $value = Core::UNDEFINED)
	{
		if($value === Core::UNDEFINED)
		{
			$fields	=	static::__structure();

			if(isset($fields[$prop]) && empty($this->$prop))
			{
				$php_type	=	Core::getPHPType($fields[$prop]);

				if($php_type === Core::TYPE_CLASS || $php_type === Core::TYPE_ARRAY)
					$this->load($prop);
			}


			return $this->$prop;
		}

		$this->$prop	=	$value;
		return $this;
	}

	/**
	 * Generic setter for several properties
	 * @access public
	 * @param array $props Array of prop_name => value
	 * @return Entity The called Entity
	 * @throws \Exception
	 */
	final public function setProps($props)
	{
		foreach($props as $prop_name => $value)
			$this->$prop_name	=	$value;

		return $this;
	}

	/**
	 * Prevision for the future when we'll handle Entity inheritance
	 *
	 * Inidicates if the called class is a subclass of a subclass of Entity
	 * @static
	 * @access public
	 * @return bool True if it's a subclass. False otherwise.
	 */
	public static function isAnInheritedClass()
	{
		return get_parent_class(get_called_class()) !== 'EntityPHP\Entity';
	}

	/**
	 * Count the number of entities of the called class
	 * @static
	 * @access public
	 * @param string $where optional The query used to filter count
	 * @param Array $values Values of variables noted as ? in $where (default: array())
	 * @return int Total of entities
	 * @throws \Exception
	 */
	final public static function count($where = null, Array $values = array())
	{
		$entity	=	get_called_class();
		if($entity !== 'EntityPHP\Entity')
		{
			//No filter, simpler way of process
			if(empty($where))
			{
				$query	=	Core::$current_db->query('SELECT NULL FROM '.$entity::getTableName().$where);
				return $query->rowCount();
			}

			//Filter, we need to create an EntityRequest!
			$request	=	$entity::createRequest()
								->select($entity::getIdName())
								->where($where, $values)
								->exec();

			return count($request);
		}

		throw new \Exception('Entity::count() -> Only a subclass of Entity can call this method.');
	}

	/**
	 * Save a new Entity instance in database
	 * @static
	 * @access public
	 * @param Entity $obj Instance to persist
	 * @return Entity The stored instance
	 * @throws \Exception
	 */
	public static function add(Entity $obj)
	{
		$className	=	get_called_class();

		if($className !== 'EntityPHP\Entity')
		{
			if($obj instanceof $className)
			{
				$parent		=	$className::getTableName();
				$id_name	=	$className::getIdName();
				$sql		=	$obj->prepareDataForSQL();
				Core::$current_db->exec('INSERT INTO '.$parent.' ('.implode(',',$sql['fields']).') VALUES ('.implode(',',$sql['values']).')');
				$obj->$id_name	=	Core::$current_db->lastInsertId();

				if(empty($obj->$id_name))
				{
					$errorInfo	=	Core::$current_db->errorInfo();
					throw new \Exception('Entity::add(Entity $obj) -> '.array_pop($errorInfo));
				}

				foreach($sql['foreign'] as $request)
					Core::$current_db->exec($request);

				return $obj;
			}
			else
				throw new \Exception('Entity::add(Entity $obj) -> given $obj is not an instance of this class.');
		}
		throw new \Exception('Entity::add(Entity $obj) -> Only a subclass of Entity can call this method.');
	}

	/**
	 * Save a list of Entity instances in database
	 * @static
	 * @access public
	 * @param EntityArray $list Instances to persist
	 * @return Array A list containing the stored instances
	 * @throws \Exception
	 */
	public static function addMultiple(Array $list)
	{
		$className	=	get_called_class();

		if($className !== 'EntityPHP\Entity')
		{
			$tableName		=	$className::getTableName();
			$id_name		=	$className::getIdName();
			$sql_request	=	null;
			$foreignSQL		=	array();

			foreach($list as $instance)
			{
				if($instance instanceof $className)
				{
					$sql	=	$instance->prepareDataForSQL();

					if( ! $sql_request)
						$sql_request	=	'INSERT INTO '.$tableName.' ('.implode(',',$sql['fields']).') VALUES ';

					$sql_request	.=	'('.implode(',',$sql['values']).'),';
				}
				else
					throw new \Exception('Entity::addMultiple(Array $list) -> an object in given $list is not an instance of this class.');
			}

			Core::$current_db->exec(substr($sql_request, 0, -1));

			$instances	=	$className::createRequest()
								->orderBy($id_name.' DESC')
								->getOnly(count($list))
								->exec()
								->reverse();

			foreach($instances as $index => $instance)
			{
				$list[$index]->$id_name	=	$instance->getId();

				//We can generate FK requests here since we have correct ids only now
				$sql		=	$list[$index]->prepareDataForSQL(true);
				$foreignSQL	=	array_merge($foreignSQL, $sql['foreign']);
			}

			foreach($foreignSQL as $request)
				Core::$current_db->exec($request);

			return $instances;
		}

		throw new \Exception('Entity::addMultiple(Array $list) -> Only a subclass of Entity can call this method.');
	}

	/**
	 * Update an Entity instance in database
	 * @static
	 * @access public
	 * @param Entity $obj Instance to persist
	 * @return int The number of affected rows
	 * @throws \Exception
	 */
	final public static function update(Entity $obj)
	{
		$className	=	get_called_class();
		if($className !== 'EntityPHP\Entity')
		{
			if($obj instanceof $className)
			{
				$tableName	=	$className::getTableName();
				$idName		=	$className::getIdName();
				$objId		=	intval($obj->getId());
				$affected	=	0;
				$query		=	Core::$current_db->query('SELECT '.$idName.' FROM '.$tableName.' WHERE '.$idName.'='.$objId);

				if($query->rowCount() > 0)
				{
					$sql	=	$obj->prepareDataForSQL(true);
					$set	=	array();

					for($i = 0, $l = count($sql['fields']); $i < $l; $i++)
						$set[]	=	$sql['fields'][$i].'='.$sql['values'][$i];

					foreach($sql['foreign'] as $request)
					{
						$affected += Core::$current_db->exec($request);
					}

					$affected += Core::$current_db->exec('UPDATE '.$tableName.' SET '.implode(',',$set).' WHERE '.$idName.'='.$objId);

					return $affected;
				}
				throw new \Exception('Entity::update(Entity $obj) -> given $obj seems to not exist in the DB.');
			}
			throw new \Exception('Entity::update(Entity $obj) -> given $obj is not an instance of this class.');
		}
		throw new \Exception('Entity::update(Entity $obj) -> Only a subclass of Entity can call this method.');
	}

	/**
	 * Delete an Entity instance in database
	 * @static
	 * @access public
	 * @param Entity $obj Instance to delete
	 * @throws \Exception
	 */
	final public static function delete(Entity $obj)
	{
		// TODO: What about occurences in junction tables?
		$className	=	get_called_class();
		if($className !== 'EntityPHP\Entity')
		{
			if($obj instanceof $className)
				static::deleteById($obj->getId());
			else
				throw new \Exception('Entity::delete(Entity $obj) -> given $obj is not an instance of this class.');
		}
		else
			throw new \Exception('Entity::delete(Entity $obj) -> Only a subclass of Entity can call this method.');
	}

	/**
	 * Delete an Entity instance in database
	 * @static
	 * @access public
	 * @param Integer $id ID of the Entity to delete
	 * @throws \Exception
	 */
	final public static function deleteById($id)
	{
		$className	=	get_called_class();

		if($className !== 'EntityPHP\Entity')
		{
			$tableName	=	$className::getTableName();
			$idName		=	$className::getIdName();
			$id			=	intval($id);
			$query		=	Core::$current_db->query('SELECT '.$idName.' FROM '.$tableName.' WHERE '.$idName.'='.$id);

			if($query->rowCount() > 0)
				Core::$current_db->exec('DELETE FROM '.$tableName.' WHERE '.$idName.'='.$id);
			else
				throw new \Exception('Entity::deleteById(Integer $id) -> given $id seems to not exist in the DB.');
		}
		else
			throw new \Exception('Entity::deleteById(Integer $id) -> Only a subclass of Entity can call this method.');
	}

	/**
	 * Delete a list of Entity instances in database
	 * @static
	 * @access public
	 * @param EntityArray $list Instances to delete
	 * @throws \Exception
	 */
	final public static function deleteMultiple(EntityArray $list)
	{
		$className	=	get_called_class();

		if($className !== 'EntityPHP\Entity')
		{
			$tableName	=	$className::getTableName();
			$idName		=	$className::getIdName();
			$ids		=	array();

			foreach($list as $instance)
			{
				if($instance instanceof $className)
				{
					$instanceId	=	intval($instance->getId());
					$query		=	Core::$current_db->query('SELECT '.$idName.' FROM '.$tableName.' WHERE '.$idName.'='.$instanceId);

					if($query->rowCount() > 0)
						$ids[]	=	$instanceId;
					else
						throw new \Exception('Entity::deleteMultiple(EntityArray $list) -> an object in given $list seems to not exist in the DB.');
				}
				else
					throw new \Exception('Entity::deleteMultiple(EntityArray $list) -> an object in given $list is not an instance of this class.');
			}

			Core::$current_db->exec('DELETE FROM '.$tableName.' WHERE '.$idName.' IN ('.implode(',',$ids).')');
		}
		else
			throw new \Exception('Entity::deleteMultiple(EntityArray $list) -> Only a subclass of Entity can call this method.');
	}

	/**
	* Getter/setter for properties of a junction between this and another entity
	* @access public
	* @param string $field The field that joins the two entity
	* @param Entity $otherEntity The other entity to join
	* @param array $properties Properties to set
	* @return Entity/array The list of properties if getting properties, the Entity this was called on otheriwse
	*/
	public function junctionProperties($field, $otherEntity, $properties = Core::UNDEFINED)
	{
		$className      =	get_class($this);
		$otherClassName =	get_class($otherEntity);
		$fields         =	static::__structure();

		// Check that the asked field exists
		if( ! isset($fields[$field]))
			throw new \Exception('Entity::junctionProperties() : "'.$className.'" has no property named "'.$field.'".');

		$php_type	=	Core::getPHPType($fields[$field]);

		// Check that the field is a junction table with properties
		if($php_type !== Core::TYPE_ASSOC_ARRAY || ! class_exists(key($fields[$field])))
			throw new \Exception('Entity::junctionProperties() : Field '. $field .' of '.$className.'" is not designed to be a junction table with properties.');

		// Check that the other entity is of the right type
		if(key($fields[$field]) != $otherClassName)
			throw new \Exception('Entity::junctionProperties() : Expected entity of type '.key($fields[$field]).', got type '. $otherClassName .' instead.');

		$tableName	=	static::getTableName();

		// We want to get the properties
		if($properties === Core::UNDEFINED)
		{
			$properties = array();

			$query = Core::$current_db->query(
			'SELECT * FROM '.$tableName.'2'.$field. ' WHERE id_'.$tableName.'='.$this->getId() . ' AND id_' . $field . '=' . $otherEntity->getId());

			$result = $query->fetch(\PDO::FETCH_ASSOC);

			if($result !== false)
			{
				foreach($result as $propertyName => $propertyValue)
				{
					// We don't care about IDs
					if(substr($propertyName, 0, 3) !== 'id_')
						$properties[$propertyName] = $propertyValue;
				}
			}

			return $properties;
		}
		// We want to set the properties
		else
		{
			$availableProperties = array_keys($fields[$field][$otherClassName]);

			foreach($properties as $propertyName => $propertyValue)
			{
				// Check that the passed properties actually exists in the specification
				if(! in_array($propertyName, $availableProperties))
					throw new \Exception('Entity::junctionProperties() : Passed property '. $propertyName .' is not part of the '.$field.' field.');
			}

			// Check if the junction already exists
			$query = Core::$current_db->query(
				'SELECT NULL FROM '.$tableName.'2'.$field. ' WHERE id_'.$tableName.'='.$this->getId() . ' AND id_' . $field . '=' . $otherEntity->getId());

			// Update
			if($query->rowCount() > 0)
			{
				$set = [];

				foreach($properties as $propertyName => $propertyValue)
				{
					// Get the type of the property
					$php_type	=	Core::getPHPType($fields[$field][$otherClassName][$propertyName]);
					$value  	=	Core::convertValueForSql($php_type, $propertyValue);

					$set[]  	=	$propertyName . "=" . $value;
				}

				Core::$current_db->exec(
					'UPDATE '.$tableName.'2'.$field. ' SET '.implode(',',$set).' WHERE id_'.$tableName.'='.$this->getId() . ' AND id_' . $field . '=' . $otherEntity->getId());
			}
			// Insert
			else
			{
				// Start with the IDs for the current entity and the other entity
				$fieldsSQL = ['id_'.$tableName, 'id_' . $field];
				$valuesSQL = [$this->getId(), $otherEntity->getId()];

				// Construct the rest of the query
				foreach($properties as $propertyName => $propertyValue)
				{
					// Get the type of the property
					$php_type   	=	Core::getPHPType($fields[$field][$otherClassName][$propertyName]);
					$fieldsSQL[]	=	$propertyName;
					$valuesSQL[]	=	Core::convertValueForSql($php_type, $propertyValue);
				}

				Core::$current_db->exec('INSERT INTO '.$tableName.'2'.$field. ' ('.implode(',',$fieldsSQL).') VALUES ('.implode(',',$valuesSQL).')');
			}

			return $this;
		}
	}

	/**
	 * Allow to echo an Entity
	 * @access public
	 * @return string The string representation of the Entity
	 */
	public function __toString()
	{
		return '<pre>'.print_r($this, true).'</pre>';
	}

	/**
	 * Return a JSON representation of the Entity
	 * @access public
	 * @return string The JSON representation of the Entity
	 */
	public function toJSON()
	{
		return json_encode($this->toArray(), JSON_FORCE_OBJECT);
	}

	/**
	 * Return an associative array representation of the Entity
	 * @access public
	 * @return array The array representation of the Entity
	 */
	public function toArray()
	{
		$array	=	array();

		foreach($this as $key => $value)
			$array[$key]	=	$value;

		return $array;
	}
}

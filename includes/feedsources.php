<?php

/**
 * Functions relating to working with multiple feed sources.
 */
class FeedSources
{
	/**
	 * Load all the currently active sources from the database
	 */
	public static function loadAllSources()
	{
		$results = array();
		
		$db = Database::getDB();
		$result = $db->query('
			SELECT id, type, username, extra_params, latest_id
			FROM sources
			WHERE active = 1');
			
		while ($row = $result->fetchObject())
		{
			$results[$row->id] = FeedSource::factory($row);
		}
		
		return $results;
	}
	
	/**
	 * Load items from the DB
	 * @param	int		Number of items to load
	 * @param	int		If specified, only load items before this timestamp. Assumed to be sanitised already!
	 */
	public static function loadItems($count = 10, $before_date = null)
	{
		// Grab all the sources
		$sources = self::loadAllSources();
		
		$results = array();
		
		$db = Database::getDB();
		// TODO: Can I use a prepared statement here? The date bit is optional so am not too sure.
		$query = '
			SELECT
				items.id, items.original_id, UNIX_TIMESTAMP(items.date) AS date, items.text, 
				items.description, items.source_id, items.extra_data, sources.type, items.url
			FROM items
				INNER JOIN sources ON items.source_id = sources.id
			WHERE visible = 1';
		
		// If we're getting all the items since a particular date
		// NOTE: Assumes $before_date has been sanitised.
		if ($before_date != null)
		{
			$query .= '
				AND items.date < FROM_UNIXTIME(' . $before_date . ')';
		}
		
		$query .= '
			ORDER BY items.date DESC
			LIMIT ' . $count;
			
		$result = $db->query($query);
			
		while ($row = $result->fetchObject())
		{
			$row->extra_data = unserialize($row->extra_data);
			$results[] = $sources[$row->source_id]->loadFromDB($row);
		}
		
		return $results;
	}
	
	/**
	 * Clean up duplicates that have a modified date of today
	 */
	public static function removeDuplicateItems()
	{
		$db = Database::getDB();
		
		// TODO: Can this be made into a stored procedure?
		$result = $db->query('
			UPDATE items
			INNER JOIN (
				-- All items
				SELECT id
				FROM items
				WHERE update_date >= DATE_SUB(NOW(), INTERVAL 1 DAY)
				-- Except the first instance of it
				AND id NOT IN
				(
					SELECT MIN(id)
					FROM items
					WHERE update_date >= DATE_SUB(NOW(), INTERVAL 1 DAY)
					GROUP BY text
				)
			) duplicates
			SET visible = 0
			WHERE items.id = duplicates.id');
			
		return $result->rowCount();
	}
}

?>
<?
/*
    ***** BEGIN LICENSE BLOCK *****
    
    This file is part of the Zotero Data Server.
    
    Copyright © 2010 Center for History and New Media
                     George Mason University, Fairfax, Virginia, USA
                     http://zotero.org
    
    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU Affero General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.
    
    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU Affero General Public License for more details.
    
    You should have received a copy of the GNU Affero General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.
    
    ***** END LICENSE BLOCK *****
*/

class Zotero_Tags extends Zotero_DataObjects {
	protected static $ZDO_object = 'tag';
	
	public static $maxLength = 255;
	
	private static $tagsByID = array(); // grouped by libraryID
	private static $namesByHash = array();
	
	/*
	 * Returns a tag and type for a given tagID
	 */
	public static function get($libraryID, $tagID, $skipCheck=false) {
		if (!$libraryID) {
			throw new Exception("Library ID not provided");
		}
		
		if (!$tagID) {
			throw new Exception("Tag ID not provided");
		}
		
		if (isset(self::$tagsByID[$libraryID][$tagID])) {
			return self::$tagsByID[$libraryID][$tagID];
		}
		
		if (!$skipCheck) {
			$sql = 'SELECT COUNT(*) FROM tags WHERE tagID=?';
			$result = Zotero_DB::valueQuery($sql, $tagID, Zotero_Shards::getByLibraryID($libraryID));
			if (!$result) {
				return false;
			}
		}
		
		$tag = new Zotero_Tag;
		$tag->libraryID = $libraryID;
		$tag->id = $tagID;
		if (!self::$tagsByID[$libraryID]) {
			self::$tagsByID[$libraryID] = array();
		}
		self::$tagsByID[$libraryID][$tagID] = $tag;
		return self::$tagsByID[$libraryID][$tagID];
	}
	
	
	/*
	 * Returns array of all tagIDs for this tag (of all types)
	 */
	public static function getIDs($libraryID, $name) {
		$sql = "SELECT tagID FROM tags WHERE libraryID=? AND name=?";
		$tagIDs = Zotero_DB::columnQuery($sql, array($libraryID, $name), Zotero_Shards::getByLibraryID($libraryID));
		if (!$tagIDs) {
			return array();
		}
		return $tagIDs;
	}
	
	
	public static function getAllAdvanced($libraryID, $params) {
		$results = array('objects' => array(), 'total' => 0);
		
		$sql = "SELECT SQL_CALC_FOUND_ROWS tagID FROM tags WHERE libraryID=? ";
		$sqlParams = array($libraryID);
		
		if (!empty($params['q'])) {
			if (!is_array($params['q'])) {
				$params['q'] = array($params['q']);
			}
			foreach ($params['q'] as $q) {
				$sql .= "AND name LIKE ? ";
				$sqlParams[] = "%$q%";
			}
		}
		
		if (!empty($params['fq'])) {
			if (!is_array($params['fq'])) {
				$params['fq'] = array($params['fq']);
			}
			foreach ($params['fq'] as $fq) {
				$facet = explode(":", $fq);
				if (sizeOf($facet) == 2 && preg_match('/-?TagType/', $facet[0])) {
					switch ($facet[1]) {
						case 0:
						case 1:
							break;
						
						default:
							throw new Exception("Invalid tag type '{$facet[1]}'", Z_ERROR_INVALID_GROUP_TYPE);
					}
					
					$sql .= "AND type";
					// If first character is '-', negate
					$sql .= ($facet[0][0] == '-' ? '!' : '');
					$sql .= "=? ";
					$sqlParams[] = $facet[1];
				}
			}
		}
		
		if (!empty($params['order'])) {
			$order = $params['order'];
			if ($order == 'title') {
				// Force a case-insensitive sort
				$sql .= "ORDER BY name COLLATE utf8_unicode_ci ";
			}
			else {
				$sql .= "ORDER BY $order ";
			}
			if (!empty($params['sort'])) {
				$sql .= " " . $params['sort'] . " ";
			}
		}
		
		if (!empty($params['limit'])) {
			$sql .= "LIMIT ?, ?";
			$sqlParams[] = $params['start'] ? $params['start'] : 0;
			$sqlParams[] = $params['limit'];
		}
		
		$shardID = Zotero_Shards::getByLibraryID($libraryID);
		
		$ids = Zotero_DB::columnQuery($sql, $sqlParams, $shardID);
		
		if ($ids) {
			$results['total'] = Zotero_DB::valueQuery("SELECT FOUND_ROWS()", false, $shardID);
			
			$tags = array();
			foreach ($ids as $id) {
				$tags[] = Zotero_Tags::get($libraryID, $id);
			}
			$results['objects'] = $tags;
		}
		
		return $results;
	}
	
	
	/*public static function getDataValuesFromXML(DOMDocument $doc) {
		$xpath = new DOMXPath($doc);
		$attr = $xpath->evaluate('//tags/tag/@name');
		$vals = array();
		foreach ($attr as $a) {
			$vals[] = $a->value;
		}
		$vals = array_unique($vals);
		return $vals;
	}*/
	
	
	public static function getLongDataValueFromXML(DOMDocument $doc) {
		$xpath = new DOMXPath($doc);
		$attr = $xpath->evaluate('//tags/tag[string-length(@name) > ' . self::$maxLength . ']/@name');
		return $attr->length ? $attr->item(0)->value : false;
	}
	
	
	/**
	 * Converts a DOMElement item to a Zotero_Tag object
	 *
	 * @param	DOMElement			$xml		Tag data as DOMElement
	 * @param	int					$libraryID	Library ID
	 * @return	Zotero_Tag						Zotero tag object
	 */
	public static function convertXMLToTag(DOMElement $xml) {
		$tag = new Zotero_Tag;
		$libraryID = (int) $xml->getAttribute('libraryID');
		$tag->libraryID = $libraryID;
		$tag->key = $xml->getAttribute('key');
		$tag->name = $xml->getAttribute('name');
		$type = (int) $xml->getAttribute('type');
		$tag->type = $type ? $type : 0;
		$tag->dateAdded = $xml->getAttribute('dateAdded');
		$tag->dateModified = $xml->getAttribute('dateModified');
		
		$itemKeys = $xml->getElementsByTagName('items');
		if ($itemKeys->length) {
			$itemKeys = explode(' ', $itemKeys->item(0)->nodeValue);
			$itemIDs = array();
			foreach ($itemKeys as $key) {
				$item = Zotero_Items::getByLibraryAndKey($libraryID, $key);
				if (!$item) {
					// Return a specific error for a wrong-library tag issue that I can't reproduce
					throw new Exception("Linked item $key of tag $libraryID/$tag->key not found", Z_ERROR_TAG_LINKED_ITEM_NOT_FOUND);
					//throw new Exception("Linked item $key of tag $libraryID/$tag->key not found", Z_ERROR_ITEM_NOT_FOUND);
				}
				$itemIDs[] = $item->id;
			}
			$tag->setLinkedItems($itemIDs);
		}
		else {
			$tag->setLinkedItems(array());
		}
		return $tag;
	}
	
	
	/**
	 * Converts a SimpleXMLElement item to a Zotero_Tag object
	 *
	 * @param	SimpleXMLElement	$xml		Tag data as SimpleXML element
	 * @param	int					$libraryID	Library ID
	 * @return	Zotero_Tag						Zotero tag object
	 */
/*	public static function convertXMLToTag(SimpleXMLElement $xml) {
		$tag = new Zotero_Tag;
		$libraryID = (int) $xml['libraryID'];
		$tag->libraryID = $libraryID;
		$tag->key = (string) $xml['key'];
		$tag->name = (string) $xml['name'];
		$type = (int) $xml['type'];
		$tag->type = $type ? $type : 0;
		$tag->dateAdded = (string) $xml['dateAdded'];
		$tag->dateModified = (string) $xml['dateModified'];
		
		$itemKeys = (string) $xml->items;
		if ($itemKeys) {
			$itemKeys = explode(' ', $itemKeys);
			$itemIDs = array();
			foreach ($itemKeys as $key) {
				$item = Zotero_Items::getByLibraryAndKey($libraryID, $key);
				if (!$item) {
					throw new Exception("Linked item $key of tag $libraryID/$tag->key not found", Z_ERROR_ITEM_NOT_FOUND);
				}
				$itemIDs[] = $item->id;
			}
			$tag->setLinkedItems($itemIDs);
		}
		else {
			$tag->setLinkedItems(array());
		}
		return $tag;
	}
*/
	
	/**
	 * Converts a Zotero_Tag object to a SimpleXMLElement item
	 *
	 * @param	object				$item		Zotero_Tag object
	 * @return	SimpleXMLElement				Tag data as SimpleXML element
	 */
	public static function convertTagToXML(Zotero_Tag $tag, $syncMode=false) {
		return $tag->toXML($syncMode);
	}
}
?>

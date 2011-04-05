<?php
/*
 * By Raphael Reitzig, 2010
 * code@verrech.net
 * http://lmazy.verrech.net
 * 
 * Modified by B. Piwowarski for inclusion in the papercite
 * WordPress plug-in
 *
 *
 * This work is subject to Creative Commons
 * Attribution-NonCommercial-ShareAlike 3.0 Unported.
 * You are free:
 *     * to Share — to copy, distribute and transmit the work
 *     * to Remix — to adapt the work
 * Under the following conditions:
 *     * Attribution — You must attribute the work in the manner specified
 *       by the author or licensor (but not in any way that suggests that
 *       they endorse you or your use of the work).
 *     * Noncommercial — You may not use this work for commercial purposes.
 *     * Share Alike — If you alter, transform, or build upon this work,
 *       you may distribute the resulting work only under the same or similar
 *       license to this one.
 * With the understanding that:
 *     * Waiver — Any of the above conditions can be waived if you get
 *       permission from the copyright holder.
 *     * Public Domain — Where the work or any of its elements is in the
 *       public domain under applicable law, that status is in no way
 *       affected by the license.
 *     * Other Rights — In no way are any of the following rights affected
 *       by the license:
 *           o Your fair dealing or fair use rights, or other applicable
 *             copyright exceptions and limitations;
 *           o The author's moral rights;
 *           o Rights other persons may have either in the work itself or
 *             in how the work is used, such as publicity or privacy rights.
 *     * Notice — For any reuse or distribution, you must make clear to
 *       others the license terms of this work. The best way to do this is
 *       with a link to the web page given below.
 *
 * Licence (short): http://creativecommons.org/licenses/by-nc-sa/3.0/
 * License (long): http://creativecommons.org/licenses/by-nc-sa/3.0/legalcode
*/
?>
<?php

// Use the slightly modified BibTex parser from PEAR.
require('lib/BibTex.php');

// Some stupid functions
require('helper.inc.php');

/**
 * This class provides a method that parses bibtex files to
 * other text formats based on a template language. See
 *   http://lmazy.verrech.net/bib2tpl/
 * for documentation.
 *
 * @author Raphael Reitzig
 * @version 1.0
 */
class BibtexConverter
{
  /**
   * BibTex parser
   *
   * @access private
   * @var Structures_BibTex
   */
  var $_parser;

  /**
   * Options array. May contain the following pairs:
   *   only  => array(['author' => regexp],['type' => regexp])
   *   group => (none|year|firstauthor|entrytype)
   *   order => (asc|desc)
   * @access private
   * @var array
   */
  var $_options;

  /**
   * Helper object with support functions.
   * @access private
   * @var Helper
   */
  var $_helper;


  /**
   * Global variables that can be accessed in the template
   */
  var $_globals;

  /**
   * Constructor.
   *
   * @access public
   * @param array options Options array. May contain the following pairs:
   *   only  => array(['author' => 'regexp'],['entrytype' => 'regexp'])
   *   group => (none|year|firstauthor|entrytype)  
   *   group-order => (asc|desc|none)
   *   sort => (none|year|firstauthor|entrytype)
   *   order => (asc|desc|none)
   *   key_format => (numeric)
   *   lang  => any string $s as long as proper lang/$s.php exists
   * @return void
   */
  function BibtexConverter($options=array())
  {

    $this->_parser = new Structures_BibTex(array('removeCurlyBraces' => true));

    // Default options
    $this->_options = array(
      'only'  => array(),

      'anonymous-whole' => false,

      'group' => 'year',
      'group-order' => 'desc',

      'sort' => 'none',
      'order' => 'none',

      'lang' => 'en',

      'key_format' => 'numeric'
    );

    // Overwrite specified options
    foreach ( $this->_options as $key => $value )
    {
      $this->_options[$key] = $options[$key];
    }

    /* Load translations.
     * We assume that the english language file is always there.
     */
    if ( is_readable(dirname(__FILE__).'/lang/'.$this->_options['lang'].'.php') )
    {
      require('lang/'.$this->_options['lang'].'.php');
    }
    else
    {
      require('lang/en.php');
    }
    $this->_options['lang'] = $translations;

    $this->_helper = new Helper($this->_options);
  }


  /**
   * Set a global variable
   */
  function setGlobal($name, $value) {
    $this->_globals[$name] = $value;
  }

  /**
   * Converts the given string in bibtex format to a string whose format
   * is defined by the passed template string.
   *
   * @access public
   * @param string bibtex Bibtex code
   * @param string template template code
   * @return mixed Result string or PEAR_Error on failure
   */
  function convert($bibtex, $template)
  {
    // TODO Eliminate LaTeX syntax

    $this->_parser->loadString($bibtex);
    $stat = $this->_parser->parse();

    if ( !$stat ) {
      return $stat;
    }

    return $this->display($this->_parser->data, $template);
  }

  /**
   *
   * Display a pre-selected set of entries (group, sort, and 
   * translate)
   *
   * @access public
   * @param string bibtex Bibtex code
   * @param string template template code
   * @return mixed Result string or PEAR_Error on failure
   */
  function display(&$data, $template)
  {
    $this->_pre_process($data);
    $data = $this->_group($data);
    $data = $this->_sort($data);
    $this->_post_process($data);

    $text = $this->_translate($data, $template);
    return array("text" => &$text, "data" => &$data);
  }

  /**
   * This function filters data from the specified array that should
   * not be shown. Filter criteria are specified at object creation.
   *
   * This function also adds values that are assumed to be existent
   * later if they do not exist, namely <code>entryid</code>,
   * <code>firstauthor = author[0]</code>, <code>year</code> and
   * <code>month</code>. Furthermore, entries whose entrytype is not
   * translated in the specified language file are put into a distinct
   * group.
   *
   * @access private
   * @param array data Unfiltered data, that is array of entries
   * @return array Filtered data as array of entries
   */
  function _filter($data)
  {
    $result = array();

    $id = 0;
    foreach ( $data as $entry ) {
      if (    (   empty($this->_options['only']['author'])
               || preg_match('/'.$this->_options['only']['author'].'/i',
                             $this->_helper->niceAuthors($entry['author'])))
           && (   empty($this->_options['only']['entrytype'])
               || preg_match('/'.$this->_options['only']['entrytype'].'/i',
                             $entry['entrytype'])) )
      {
        $entry['year'] = empty($entry['year']) ? '0000' : $entry['year'];
        if ( empty($this->_options['lang']['entrytypes'][$entry['entrytype']]) )
        {
          $entry['entrytype'] = $this->_options['lang']['entrytypes']['unknown'];
        }
        $result[] = $entry;
      }
    }

    return $result;
  }

 /**
   * This function do some pre-processing on the entries
   */
  function _pre_process(&$data) {
    foreach ( $data as &$entry ) {
      $entry['firstauthor'] = $entry['author'][0];
      $entry['entryid'] = $id++;
    }
  }



  /**
   * This function do some post-processing on the grouped & ordered list of publications.
   * In particular, it sets the key.
   */
  function _post_process(&$data) {
    $count = 0;
    foreach ( $data as &$group ) {
      foreach ( $group as &$entry) {
	$count++;
      
	switch($this->_options["key_format"]) {
	case "numeric":
	  $entry["key"] = $count;
	  break;
	case "cite":
	  $entry["key"] = $entry["cite"];
	  break;
	default: 
	  $entry["key"] = "?";
	}
      }
    }
  }

  /**
   * This function groups the passed entries according to the criteria
   * passed at object creation.
   *
   * @access private
   * @param array data An array of entries
   * @return array An array of arrays of entries
   */
  function _group($data)
  {
    $result = array();

    if ( $this->_options['group'] !== 'none' )
    {
      foreach ( $data as $entry )
      {
        $target =  $this->_options['group'] === 'firstauthor'
	  ? $this->_helper->niceAuthor($entry['firstauthor'])
                  : $entry[$this->_options['group']];

        if ( empty($result[$target]) )
        {
          $result[$target] = array();
        }

        $result[$target][] = $entry;
      }
    }
    else
    {
      if ($this->_options["anonymous-whole"]) 
	$result[""] = $data;
      else
	$result[$this->_options['lang']['all']] = $data;
    }

    return $result;
  }

  /**
   * This function sorts the passed group of entries and the individual
   * groups if there are any.
   *
   * @access private
   * @param array data An array of arrays of entries
   * @return array A sorted array of sorted arrays of entries
   */
  function _sort(&$data)
  {
    // Sort groups if there are any
    if ( $this->_options['group_order'] !== 'none' )
    {
      uksort($data, array($this->_helper, 'group_cmp'));
    }

    // Sort individual groups
    if ( $this->_options["sort"] != "none" ) 
      foreach ( $data as &$group )
	{
	  uasort($group, array($this->_helper, 'entry_cmp'));
	}

    return $data;
  }

  /**
   * This function inserts the specified data into the specified template.
   * For template syntax see class documentation or examples.
   *
   * @access private
   * @param array data An array of arrays of entries
   * @param string template The used template
   * @return string The data represented in terms of the template
   */
  function _translate($data, $template)
  {
    $result = $template;

    // Replace global values
    $result = preg_replace('/@globalcount@/', $this->_helper->lcount($data, 2), $result);
    $result = preg_replace('/@globalgroupcount@/', count($data), $result);


    $match = array();

    // Extract entry template
    $pattern = '/@\{entry@(.*?)@\}entry@/s';
    preg_match($pattern, $template, $match);
    $this->entry_tpl = $match[1];
    $result = preg_replace($pattern, "@#entry@", $result);

    // Extract group template
    $pattern = '/@\{group@(.*?)@\}group@/s';
    preg_match($pattern, $result, $match);
    $this->group_tpl = $match[1];
    $result = preg_replace($pattern, "@#group@", $result);

    /*
    print "<div>GROUP</div>";
    print_r(htmlentities($this->group_tpl));
    print "<div>ENTRY</div>";
    print_r(htmlentities($this->entry_tpl));
    */

    // The data to be processed
    $this->_data = &$data;

    // "If-then-else" stack
    $this->_ifs = array(true);

    // Now, replace
    return preg_replace_callback(BibtexConverter::$mainPattern, array($this, "_callback"), $result);
  }

  static $mainPattern = "/@([^@]+)@([^@]*)/s";

  /**
   * Main callback function
   */
  function _callback($match) {

    $condition = $this->_ifs[sizeof($this->_ifs)-1];
    //    print "<div><b>IF: $condition</b> - <b>[1]</b>:". htmlentities($match[1]) .", <b>[2]</b>:". htmlentities($match[2]) ."</div>";

    // --- [ENDIF]
    if ($match[1][0] == ';') {
      // Remove last IF expression value
      array_pop($this->_ifs);
      $condition = $this->_ifs[sizeof($this->_ifs)-1];
      if ($condition) 
	return $match[2];
      return "";
    }

    // --- [IF]
    if ($match[1][0] == '?') {
      if (!$condition) {
	// Don't evaluate if not needed
	// -1 implies to evaluate to false the alternative (ELSE)
	$this->_ifs[] = -1;
	return "";
      }
      
      $matches = array();
      preg_match("/^\?(\w+)(?:([~=><])([^@]+))?$/", $match[1], $matches);
      $value = $this->_get_value($matches[1]);
      switch($matches[2])
	{
	case "":
	  $condition = $value ? true : false;
	  break;
	case "=":
	  $condition = $value == $matches[3];
	  break;
	case "~":
	  $condition = preg_match("/$matches[3]/",$value);
	  print "[Match $matches[3] of $value: $condition]";
	  break;
	default:
	  $condition = false;
	}
      
      $this->_ifs[] = $condition;
      if ($condition) 
	return $match[2];
      return "";
    }

    // --- [ELSE]
    if ($match[1][0] == ':') {
      // Invert the expression (if within an evaluated condition)
      $condition = $condition < 0 ? -1 : !$condition;
      $this->_ifs[sizeof($this->_ifs)-1] = $condition;
      if ($condition)
	return $match[2];
      return "";
    }

    // Get the current condition status
    if (!$condition) return "";

    // --- Group loop
    if ($match[1] == "#group") {
      $groups = "";
      foreach ( $this->_data as $groupkey => &$group )
	{
	  
	  if ( is_array($groupkey) )
	    // authors
	    $groupkey = $this->_helper->niceAuthor($key);
	  elseif ( $this->_options['group'] === 'entrytype' )
	    $groupkey = $this->_options['lang']['entrytypes'][$groupkey];
	  
	  // Set the different global variables and parse
	  $this->_globals["groupkey"] = $groupkey;
	  $this->_globals["groupid"] = md5($groupkey);
	  $this->_globals["groupcount"] = count($group);
	  $this->_group = &$group;
	  $groups .= preg_replace_callback(BibtexConverter::$mainPattern, array($this, "_callback"), $this->group_tpl);
	}

      $this->_globals["groupkey"] = null;
      $this->_group = null;

      return $groups . $match[2];
    }

    // --- Entry loop
    if ($match[1] == "#entry") {
      $entries = "";
      foreach($this->_group as &$entry) {
	$this->_entry = $entry;
	$entries .= preg_replace_callback(BibtexConverter::$mainPattern, array($this, "_callback"), $this->entry_tpl);
      }
      $this->_entry = null;
      return $entries . $match[2];
    }

    // --- Normal processing
    return $this->_get_value($match[1]).$match[2];
  }

  function _get_value($name) {
    $pos = strpos($name, ":");
    if ($pos > 0) {
      $modifier = substr($name, $pos+1);
      $name = substr($name, 0, $pos);
    }

    if ($this->_entry) {
      // Special case: author
      if ($name == "author") {
	return $this->_helper->niceAuthors($this->_entry["author"], $modifier);
      }
      
      // Entry variable
      if (array_key_exists($name, $this->_entry)) 
	return $this->_entry[$name];
    }

    // Global variable
    if (array_key_exists($name, $this->_globals)) {
      return $this->_globals[$name];
    }
  }


  


}

?>
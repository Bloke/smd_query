<?php

// This is a PLUGIN TEMPLATE for Textpattern CMS.

// Copy this file to a new name like abc_myplugin.php.  Edit the code, then
// run this file at the command line to produce a plugin for distribution:
// $ php abc_myplugin.php > abc_myplugin-0.1.txt

// Plugin name is optional.  If unset, it will be extracted from the current
// file name. Plugin names should start with a three letter prefix which is
// unique and reserved for each plugin author ("abc" is just an example).
// Uncomment and edit this line to override:
$plugin['name'] = 'smd_query';

// Allow raw HTML help, as opposed to Textile.
// 0 = Plugin help is in Textile format, no raw HTML allowed (default).
// 1 = Plugin help is in raw HTML.  Not recommended.
# $plugin['allow_html_help'] = 1;

$plugin['version'] = '0.6.0';
$plugin['author'] = 'Stef Dawson';
$plugin['author_uri'] = 'https://stefdawson.com/';
$plugin['description'] = 'Generic database access via SQL';

// Plugin load order:
// The default value of 5 would fit most plugins, while for instance comment
// spam evaluators or URL redirectors would probably want to run earlier
// (1...4) to prepare the environment for everything else that follows.
// Values 6...9 should be considered for plugins which would work late.
// This order is user-overrideable.
$plugin['order'] = '5';

// Plugin 'type' defines where the plugin is loaded
// 0 = public              : only on the public side of the website (default)
// 1 = public+admin        : on both the public and admin side
// 2 = library             : only when include_plugin() or require_plugin() is called
// 3 = admin               : only on the admin side (no Ajax)
// 4 = admin+ajax          : only on the admin side (Ajax supported)
// 5 = public+admin+ajax   : on both the public and admin side (Ajax supported)
$plugin['type'] = '0';

// Plugin "flags" signal the presence of optional capabilities to the core plugin loader.
// Use an appropriately OR-ed combination of these flags.
// The four high-order bits 0xf000 are available for this plugin's private use
if (!defined('PLUGIN_HAS_PREFS')) define('PLUGIN_HAS_PREFS', 0x0001); // This plugin wants to receive "plugin_prefs.{$plugin['name']}" events
if (!defined('PLUGIN_LIFECYCLE_NOTIFY')) define('PLUGIN_LIFECYCLE_NOTIFY', 0x0002); // This plugin wants to receive "plugin_lifecycle.{$plugin['name']}" events

$plugin['flags'] = '0';

// Plugin 'textpack' is optional. It provides i18n strings to be used in conjunction with gTxt().
// Syntax:
// ## arbitrary comment
// #@event
// #@language ISO-LANGUAGE-CODE
// abc_string_name => Localized String

/** Uncomment me, if you need a textpack
$plugin['textpack'] = <<< EOT
#@admin
#@language en-gb
abc_sample_string => Sample String
abc_one_more => One more
#@language de-de
abc_sample_string => Beispieltext
abc_one_more => Noch einer
EOT;
**/
// End of textpack

if (!defined('txpinterface'))
        @include_once('zem_tpl.php');

# --- BEGIN PLUGIN CODE ---
/**
 * smd_query
 *
 * A Textpattern CMS plugin for interacting with the Txp database:
 *  -> Run arbitrary SQL statements to query, insert, update, delete, etc
 *  -> Process each returned row through a Form/container
 *  -> Optionally filter the URL input using regular expressions, for safety
 *  -> Supports <txp:else />
 *  -> Results can be paged
 *
 * @author Stef Dawson
 * @link   https://stefdawson.com/
 * @todo   preparse=1 kills the ability to replace {tag} with
 *         <txp:smd_query_info item="tag" /> because the act of parsing the
 *         container with {tags} in it and then replacing them with real tags
 *         doesn't execute the content: it needs a second parse() which is slower.
 */
if (class_exists('\Textpattern\Tag\Registry')) {
    Txp::get('\Textpattern\Tag\Registry')
        ->register('smd_query')
        ->register('smd_query_info')
        ->register('smd_if_prev')
        ->register('smd_if_next');
}

/**
 * smd_query tag
 *
 * Perform a database query and return results in an iterable/parsable format.
 * @param  array $atts   Tag attributes
 * @param  string $thing Tag container content
 */
function smd_query($atts, $thing = null)
{
    global $pretext, $smd_query_pginfo, $thispage, $thisarticle, $thisimage, $thisfile, $thislink, $smd_query_data;

    extract(lAtts(array(
        'column'       => '',
        'table'        => '',
        'where'        => '',
        'query'        => '',
        'form'         => '',
        'pageform'     => '',
        'pagevar'      => 'pg',
        'pagepos'      => 'below',
        'colsform'     => '',
        'escape'       => '',
        'strictfields' => '0',
        'preparse'     => '0', // 0 = {replace} then parse, 1 = parse then {replace}
        'populate'     => '', // one of article, image, file, or link
        'raw_vals'     => '0',
        'urlfilter'    => '',
        'urlreplace'   => '',
        'defaults'     => '',
        'delim'        => ',',
        'paramdelim'   => ':',
        'silent'       => '0',
        'mode'         => 'auto', // auto chooses one of input (INSERT/UPDATE) or output (QUERY)
        'count'        => 'up',
        'var_prefix'   => 'smd_',
        'limit'        => 0,
        'offset'       => 0,
        'hashsize'     => '6:5',
        'label'        => '',
        'labeltag'     => '',
        'wraptag'      => '',
        'break'        => '',
        'class'        => '',
        'breakclass'   => '',
        'html_id'      => '',
        'debug'        => '0',
    ), $atts));

    // Grab the form or embedded $thing.
    $falsePart = EvalElse($thing, 0);

    $thing = ($form) ? fetch_form($form) . (($falsePart) ? '<txp:else />' . $falsePart : '') : (($thing) ? $thing : '');
    $colsform = (empty($colsform)) ? '' : fetch_form($colsform);
    $pagebit = array();

    if ($pageform) {
        $pagePosAllowed = array("below", "above");
        $paging = 1;
        $pageform = fetch_form($pageform);
        $pagepos = str_replace('smd_', '', $pagepos);
        $pagepos = do_list($pagepos, $delim);

        foreach ($pagepos as $pageitem) {
            $pagebit[] = (in_array($pageitem, $pagePosAllowed)) ? $pageitem : $pagePosAllowed[0];
        }
    }

    // Make a unique hash value for this instance so the queries
    // can be paged independently.
    $uniq = '';
    $md5 = md5($column.$table.$where.$query.$defaults);
    list($hashLen, $hashSkip) = explode(':', $hashsize);

    for ($idx = 0, $cnt = 0; $cnt < $hashLen; $cnt++, $idx = (($idx+$hashSkip) % strlen($md5))) {
        $uniq .= $md5[$idx];
    }

    $pagevar = ($pagevar == 'SMD_QUERY_UNIQUE_ID') ? $uniq : $pagevar;
    $urlfilter = (!empty($urlfilter)) ? do_list($urlfilter, $delim) : '';
    $urlreplace = (!empty($urlreplace)) ? do_list($urlreplace, $delim) : '';

    if ($debug > 0) {
        echo "++ URL FILTERS ++";
        dmp($urlfilter);
        dmp($urlreplace);
    }

    // Process any defaults.
    $spc = ($strictfields) ? 0 : 1;
    $defaults = do_list($defaults, $delim);
    $dflts = array();

    foreach ($defaults as $item) {
        $item = do_list($item, $paramdelim);
        if ($item[0] == '') continue;
        if (count($item) == 2) {
            $dflts[$item[0]] = smd_query_parse($item[1], array(''), array(''), array(''), $spc);
        }
    }

    if ($debug > 0) {
        echo "++ DEFAULTS ++";
        dmp($dflts);
    }

    // Get a list of fields to escape.
    $escapes = do_list($escape, $delim);

    foreach ($escapes as $idx => $val) {
        if ($val == '') {
            unset($escapes[$idx]);
        }
    }

    $rs = array();
    $out = array();
    $colout = $finalout = array();
    $pageout = '';

    // query overrides column/table/where.
    if ($query) {
        $query = smd_query_parse($query, $dflts, $urlfilter, $urlreplace, $spc);
        $mode = ($mode == 'auto') ? ((preg_match('/(select|show)/i', $query)) ? 'output' : 'input') : $mode;
        if ($mode == 'input') {
            $rs = ($silent) ? @safe_query($query, $debug) : safe_query($query, $debug);
        } else {
            $rs = ($silent) ? @getRows($query, $debug) : getRows($query, $debug);
        }
    } else {
        if ($column && $table) {
            // TODO: Perhaps doSlash() these? Or strip_tags?
            $column = smd_query_parse($column, $dflts, $urlfilter, $urlreplace, $spc);
            $table = smd_query_parse($table, $dflts, $urlfilter, $urlreplace, $spc);
            $where = smd_query_parse($where, $dflts, $urlfilter, $urlreplace, $spc);
            $where = ($where) ? $where : "1=1";
            $mode = 'output';
            $rs = ($silent) ? @safe_rows($column, $table, $where, $debug) : safe_rows($column, $table, $where, $debug);
        } else {
            trigger_error("You must specify at least 1 'column' and a 'table'.");
        }
    }

    if ($mode == 'output') {
        $numrows = count($rs);
        $truePart = EvalElse($thing, 1);

        if ($rs) {
            if ($debug > 1) {
                echo "++ QUERY RESULT SET ++";
                dmp($numrows . " ROWS");
                dmp($rs);
            }

            if ($limit > 0) {
                $safepage = $thispage;
                $total = $numrows - $offset;
                $numPages = ceil($total/$limit);
                $pg = (!gps($pagevar)) ? 1 : gps($pagevar);
                $pgoffset = $offset + (($pg - 1) * $limit);
                // Send paging info to txp:newer and txp:older.
                $pageout['pg'] = $pg;
                $pageout['numPages'] = $numPages;
                $pageout['s'] = $pretext['s'];
                $pageout['c'] = $pretext['c'];
                $pageout['grand_total'] = $numrows;
                $pageout['total'] = $total;
                $thispage = $pageout;
            } else {
                $pgoffset = $offset;
            }

            $rs = array_slice($rs, $pgoffset, (($limit==0) ? 99999 : $limit));
            $pagerows = count($rs);

            $replacements = $repagements = $colreplacements = array();
            $page_rowcnt = ($count == "up") ? 0 : $pagerows-1;
            $qry_rowcnt = ($count == "up") ? $pgoffset-$offset : $numrows-$pgoffset-1;
            $used_rowcnt = 1;
            $first_row = $qry_rowcnt + 1;

            // Preserve any external context.
            switch ($populate) {
                case 'article':
                    $safe = ($thisarticle) ? $thisarticle : array();
                    break;
                case 'image':
                    $safe = ($thisimage) ? $thisimage : array();
                    break;
                case 'file':
                    $safe = ($thisfile) ? $thisfile : array();
                    break;
                case 'link':
                    $safe = ($thislink) ? $thislink : array();
                    break;
            }

            foreach ($rs as $row) {
                foreach ($row as $colid => $val) {
                    // Construct the replacement arrays and global data used by the smd_query_info tag.
                    if ($page_rowcnt == 0 && $colsform) {
                        $colreplacements['{'.$colid.'}'] = ($raw_vals) ? $colid : '<txp:smd_query_info type="col" item="' . $colid. '" />';
                        $smd_query_data['col'][$colid] = $colid;
                    }

                    // Mitigate injection attacks by using an actual Txp tag instead of the raw value
                    // Note the type is specified in case the default is ever altered.
                    $escval = (in_array($colid, $escapes) ? htmlspecialchars($val, ENT_QUOTES) : $val);
                    $replacements['{'.$colid.'}'] = ($raw_vals) ? $escval : '<txp:smd_query_info type="field" item="' . $colid. '" />';
                    $smd_query_data['field'][$colid] = $escval;

                    if ($page_rowcnt == (($count == "up") ? $pagerows-1 : 0) && $pageform && $limit>0) {
                        $prevpg = (($pg-1) > 0) ? $pg-1 : '';
                        $nextpg = (($pg+1) <= $numPages) ? $pg+1 : '';
                        $rowprev = $prevpg ? $limit : 0;
                        $rownext = (($nextpg) ? ((($qry_rowcnt+$limit+1) > $total) ? $total-$qry_rowcnt-1 : $limit) : 0);

                        // These values are all generated by the plugin and are just numbers, so don't need the
                        // extra protection of being output as real tags.
                        $repagements['{'.$var_prefix.'allrows}'] = $total;
                        $repagements['{'.$var_prefix.'pages}'] = $numPages;
                        $repagements['{'.$var_prefix.'prevpage}'] = $prevpg;
                        $repagements['{'.$var_prefix.'thispage}'] = $pg;
                        $repagements['{'.$var_prefix.'nextpage}'] = $nextpg;
                        $repagements['{'.$var_prefix.'row_start}'] = $first_row;
                        $repagements['{'.$var_prefix.'row_end}'] = $qry_rowcnt + 1;
                        $repagements['{'.$var_prefix.'rows_prev}'] = $rowprev;
                        $repagements['{'.$var_prefix.'rows_next}'] = $rownext;
                        $repagements['{'.$var_prefix.'query_unique_id}'] = $uniq;

                        $smd_query_data['page'][$var_prefix.'allrows'] = $total;
                        $smd_query_data['page'][$var_prefix.'pages'] = $numPages;
                        $smd_query_data['page'][$var_prefix.'prevpage'] = $prevpg;
                        $smd_query_data['page'][$var_prefix.'thispage'] = $pg;
                        $smd_query_data['page'][$var_prefix.'nextpage'] = $nextpg;
                        $smd_query_data['page'][$var_prefix.'row_start'] = $first_row;
                        $smd_query_data['page'][$var_prefix.'row_end'] = $qry_rowcnt + 1;
                        $smd_query_data['page'][$var_prefix.'rows_prev'] = $rowprev;
                        $smd_query_data['page'][$var_prefix.'rows_next'] = $rownext;
                        $smd_query_data['page'][$var_prefix.'query_unique_id'] = $uniq;
                        $smd_query_pginfo = $repagements;
                    }
                }

                $allrows = ($limit > 0) ? $total : $numrows-$pgoffset;
                $pages = ($limit > 0) ? $numPages : 1;
                $currpage = ($limit > 0) ? $pg : 1;
                $replacements['{'.$var_prefix.'allrows}'] = $allrows;
                $replacements['{'.$var_prefix.'rows}'] = $pagerows;
                $replacements['{'.$var_prefix.'pages}'] = $pages;
                $replacements['{'.$var_prefix.'thispage}'] = $currpage;
                $replacements['{'.$var_prefix.'thisindex}'] = $page_rowcnt;
                $replacements['{'.$var_prefix.'thisrow}'] = $page_rowcnt + 1;
                $replacements['{'.$var_prefix.'cursorindex}'] = $qry_rowcnt;
                $replacements['{'.$var_prefix.'cursor}'] = $qry_rowcnt + 1;
                $replacements['{'.$var_prefix.'usedrow}'] = $used_rowcnt;

                $smd_query_data['field'][$var_prefix.'allrows'] = $allrows;
                $smd_query_data['field'][$var_prefix.'rows'] = $pagerows;
                $smd_query_data['field'][$var_prefix.'pages'] = $pages;
                $smd_query_data['field'][$var_prefix.'thispage'] = $currpage;
                $smd_query_data['field'][$var_prefix.'thisindex'] = $page_rowcnt;
                $smd_query_data['field'][$var_prefix.'thisrow'] = $page_rowcnt + 1;
                $smd_query_data['field'][$var_prefix.'cursorindex'] = $qry_rowcnt;
                $smd_query_data['field'][$var_prefix.'cursor'] = $qry_rowcnt + 1;
                $smd_query_data['field'][$var_prefix.'usedrow'] = $used_rowcnt;

                if ($debug > 0) {
                    echo "++ REPLACEMENTS ++";
                    dmp($replacements);
                }

                // Attempt to set up contexts to allow TXP tags to be used.
                switch ($populate) {
                    case 'article':
                        if (function_exists('article_format_info')) {
                            article_format_info($row);
                        } else {
                            // TO REMOVE.
                            populateArticleData($row);
                        }
                        $thisarticle['is_first'] = ($page_rowcnt == 1);
                        $thisarticle['is_last'] = (($page_rowcnt + 1) == $pagerows);
                        break;
                    case 'image':
                        $thisimage = image_format_info($row);
                        break;
                    case 'file':
                        $thisfile = file_download_format_info($row);
                        break;
                    case 'link':
                        if (function_exists('link_format_info')) {
                            $thislink = link_format_info($row);
                        } else {
                            // TO REMOVE.
                            $thislink = array(
                                'id'          => $row['id'],
                                'linkname'    => $row['linkname'],
                                'url'         => $row['url'],
                                'description' => $row['description'],
                                'date'        => $row['uDate'],
                                'category'    => $row['category'],
                                'author'      => $row['author'],
                            );
                        }
                        break;
                }

                $pp = ($preparse) ? strtr(parse($truePart), $replacements) : parse(strtr($truePart, $replacements));
                $pp = trim(($raw_vals == '0') ? parse($pp) : $pp);

                if ($pp) {
                    $out[] = $pp;
                    $used_rowcnt++;
                }

                $qry_rowcnt = ($count=="up") ? $qry_rowcnt+1 : $qry_rowcnt-1;
                $page_rowcnt = ($count=="up") ? $page_rowcnt+1 : $page_rowcnt-1;
            }

            if ($out) {
                if ($colreplacements) {
                    $colout[] = ($preparse) ? parse(strtr(parse($colsform), $colreplacements)) : parse(strtr($colsform, $colreplacements));
                }

                if ($repagements) {
                    // Doesn't need an extra parse in the preparse phase because none of the replacements come
                    // from outside the plugin so they are used {verbatim}.
                    $pageout = ($preparse) ? strtr(parse($pageform), $repagements) : parse(strtr($pageform, $repagements));
                }

                // Make up the final output.
                if (in_array("above", $pagebit)) {
                    $finalout[] = $pageout;
                }

                $finalout[] = doLabel($label, $labeltag).doWrap(array_merge($colout, $out), $wraptag, $break, $class, $breakclass, '', '', $html_id);

                if (in_array("below", $pagebit)) {
                    $finalout[] = $pageout;
                }

                // Restore the paging outside the plugin container.
                if ($limit > 0) {
                    $thispage = $safepage;
                }

                // Restore the other contexts.
                if (isset($safe)) {
                    switch ($populate) {
                        case 'article':
                            $thisarticle = $safe;
                            break;
                        case 'image':
                            $thisimage = $safe;
                            break;
                        case 'file':
                            $thisfile = $safe;
                            break;
                        case 'link':
                            $thislink = $safe;
                            break;
                    }
                }

                return join('', $finalout);
            }
        } else {
            return parse(EvalElse($thing, 0));
        }
    }

    return '';
}

/**
 * Internal function to parse replacement variables and globals
 *
 * URL variables are optionally run through preg_replace() to sanitize them.
 *
 * @param  string $item  The element to scan for replacements
 * @param  array  $dflts Default values to apply if any replacements are empty
 * @param  array  $pat   A set of regex search patterns
 * @param  array  $rep   A set of regex search replacements (default='', remove whatever matches)
 * @param  bool   $lax   Whether to allow spaces in pattern matches
 */
function smd_query_parse($item, $dflts = array(''), $pat = array(''), $rep = array(''), $lax = true)
{
    global $pretext, $thisarticle, $thisimage, $thisfile, $thislink, $variable;

    $item = html_entity_decode($item);

    // Sometimes pesky Unicode is not compiled in. Detect if so and fall back to ASCII.
    if (!@preg_match('/\pL/u', 'a')) {
        $modRE = ($lax) ? '/(\?)([A-Za-z0-9_\- ]+)/' : '/(\?)([A-Za-z0-9_\-]+)/';
    } else {
        $modRE = ($lax) ? '/(\?)([\p{L}\p{N}\p{Pc}\p{Pd}\p{Zs}]+)/' : '/(\?)([\p{L}\p{N}\p{Pc}\p{Pd}]+)/';
    }

    $numMods = preg_match_all($modRE, $item, $mods);

    for ($modCtr = 0; $modCtr < $numMods; $modCtr++) {
        $modChar = $mods[1][$modCtr];
        $modItem = trim($mods[2][$modCtr]);
        $lowitem = strtolower($modItem);
        $urlvar = $svrvar = '';

        if (gps($lowitem) != '') {
            $urlvar = doSlash(gps($lowitem));

            if ($urlvar && $pat) {
                $urlvar = preg_replace($pat, $rep, $urlvar);
            }
        }

        if (serverSet($modItem) != '') {
            $svrvar = doSlash(serverSet($modItem));

            if ($svrvar && $pat) {
                $svrvar = preg_replace($pat, $rep, $svrvar);
            }
        }

        if (isset($variable[$lowitem]) && $variable[$lowitem] != '') {
            $item = str_replace($modChar.$modItem, $variable[$lowitem], $item);
        } elseif ($svrvar != '') {
            $item = str_replace($modChar.$modItem, $svrvar, $item);
        } elseif (isset($thisimage[$lowitem]) && !empty($thisimage[$lowitem])) {
            $item = str_replace($modChar.$modItem, $thisimage[$lowitem], $item);
        } elseif (isset($thisfile[$lowitem]) && !empty($thisfile[$lowitem])) {
            $item = str_replace($modChar.$modItem, $thisfile[$lowitem], $item);
        } elseif (isset($thislink[$lowitem]) && !empty($thislink[$lowitem])) {
            $item = str_replace($modChar.$modItem, $thislink[$lowitem], $item);
        } elseif (array_key_exists($lowitem, $pretext) && !empty($pretext[$lowitem])) {
            $item = str_replace($modChar.$modItem, $pretext[$lowitem], $item);
        } elseif (isset($thisarticle[$lowitem]) && !empty($thisarticle[$lowitem])) {
            $item = str_replace($modChar.$modItem, $thisarticle[$lowitem], $item);
        } elseif ($urlvar != '') {
            $item = str_replace($modChar.$modItem, $urlvar, $item);
        } elseif (isset($dflts[$lowitem])) {
            $item = str_replace($modChar.$modItem, $dflts[$lowitem], $item);
        } else {
            $item = str_replace($modChar.$modItem, $modItem, $item);
        }
    }

    return $item;
}

// Convenience tag for those that prefer the security of a tag over {replacements}
function smd_query_info($atts, $thing = null)
{
    global $smd_query_data;

    extract(lAtts(array(
        'type'    => 'field', // or 'col' or 'page'
        'item'    => '',
        'wraptag' => '',
        'break'   => '',
        'class'   => '',
        'debug'   => 0,
    ), $atts));

    $qdata = is_array($smd_query_data) ? $smd_query_data : array();

    if ($debug) {
        echo '++ AVAILABLE INFO ++';
        dmp($qdata);
    }

    $items = do_list($item);
    $out = array();

    foreach ($items as $it) {
        if (isset($qdata[$type][$it])) {
            $out[] = $qdata[$type][$it];
        }
    }

    return doWrap($out, $wraptag, $break, $class);
}

/**
 * Convenience tags to check if there's a previous page defined
 *
 * Could also use smd_if plugin.
 *
 * @param  array $atts   Tag attributes
 * @param  string $thing Tag container content
 */
function smd_query_if_prev($atts, $thing)
{
    global $smd_query_pginfo;

    $res = $smd_query_pginfo && $smd_query_pginfo['{smd_prevpage}'] != '';

    return parse(EvalElse(strtr($thing, $smd_query_pginfo), $res));
}

/**
 * Convenience tags to check if there's a next page defined
 *
 * Could also use smd_if plugin.
 *
 * @param  array $atts   Tag attributes
 * @param  string $thing Tag container content
 */
function smd_query_if_next($atts, $thing)
{
    global $smd_query_pginfo;

    $res = $smd_query_pginfo && $smd_query_pginfo['{smd_nextpage}'] != '';

    return parse(EvalElse(strtr($thing, $smd_query_pginfo), $res));

}
# --- END PLUGIN CODE ---
if (0) {
?>
<!--
# --- BEGIN PLUGIN HELP ---
h1. smd_query

The laziest tag ever! Allows you to make ad-hoc queries to the database and process the results, row by row, in a form or container.

h2. Features

* Supports simple queries with a reduced syntax (SELECT cols FROM table WHERE clause) or your own custom queries
* Read information from any part of the current article, image (planned), file, link, @<txp:variable />@ or URL line. If any fields are missing you can specify a default value
* Optionally filter the URL input using regular expressions, for safety
* Each row can be passed to a Form or to the plugin's container to display the results
* @<txp:else />@ supported
* Column headings may be output using a second form
* Result sets can be paginated, with support for a paging form

h2. Installation / Uninstallation

Download the plugin from either "textpattern.org":https://textpattern.org/plugins/976/smd_query, or the "software page":https://stefdawson.com/sw, paste the code into the Txp Admin -> Plugins pane, install and enable the plugin. Visit the "forum thread":https://forum.textpattern.com/viewtopic.php?id=27279 for more info or to report on the success or otherwise of the plugin.

Uninstall by simply deleting the plugin from the Admin->Plugins pane.

h2. Tag: @<txp:smd_query />@ usage

Use this tag in any page, form, article, file, link, etc context to grab stuff from the database. The plugin can operate in one of two modes:

# simple mode just allows @SELECT stuff FROM table WHERE clause@
# advanced mode uses the @query@ attribute so you can design your own query. It can include COUNT (*), joins, anything; even INSERT and UPDATE if you are careful

Use the following attributes to configure the plugin. The default value is unset unless otherwise noted.

h3(#attributes). Attributes

h4. Simple queries

; %column%
: Comma-separated list of columns to retrieve from the database.
; %table%
: Name of the table to retrieve the columns from (non-Txp tables are also supported if they are in the same database).
; %where%
: Any extra clause you wish to specify
: Default: @1=1@ (i.e. "the whole darn table").

h4. Advanced queries

; %query%
: Any valid query you like can be used here. Overrides @column@, @table@ and @where@.
; %mode%
: You should not need to alter this parameter as it is set to automatically detect the query type.
: If you are using SELECT or SHOW statements, the mode is set to @output@.
: For any other type of query (e.g. INSERT/UPDATE) it is set to @input@.
: The only difference between the two modes is that if it's set to @input@ you can use smd_query as a self-closing tag because it does not use the form/container to parse the result set. Change this parameter only if the plugin detects the mode wrongly or you are doing something insanely clever with your query.
: Default: @auto@.
; %populate%
: You usually use @{replacement}@ variables or @<txp:smd_query_info />@ in your smd_query container to access  any fields you grab, but if you are dealing with the native Textpattern content types (article, image, file, link) you can inform smd_query which of the four you are using via this attribute. You can then use regular Txp tags inside your form/container. See "example 13":#eg13.

h4. Forms and paging

; %form%
: The Txp Form to use to parse each returned row. See "replacements":#replacements.
: If not specified, the plugin will use anything contained between its opening and closing tag.
: @<txp:else />@ is supported.
; %colsform%
: Optional Txp form to parse any header row containing column names (of limited use).
; %pageform%
: Optional Txp form used to specify the layout of any paging navigation and statistics such as page number, quantity of records per page, total number of records, etc.
; %pagepos%
: The position of the paging information. Options are:
:: @below@.
:: @above@.
:: both of them separated by @delim@.
: Default: @below@.
; %preparse%
: Alter the parse order of the form/container. Options are:
:: 0: any container or Form will have its replacement variables swapped for content first and _then_ it will be parsed to process any Txp tags.
:: 1: the Form/container is parsed first before the replacements are made. This is very useful when using the @<txp:yield />@ tag (see "example 9":#eg9).
: Default: 0
; %limit%
: Show this many results per page. Has no bearing on any SQL @LIMIT@ you may set.
: Setting a @limit@ in the smd_query tag switches paging on automatically so you can use the @<txp:older />@ and @<txp:newer />@ tags inside your @pageform@ to step through each page of results. You may also construct your own paging (see "example 11":#eg11).
; %offset%
: Skip this many rows before outputting the results.
; %pagevar%
: If you are putting an smd_query on the same page as a standard article list, the built-in newer and older tags will clash with those of smd_query; clicking next/prev will step through both your result set and your article list. Specify a different variable name here so the two lists can be navigated independently, e.g. @pagevar="qpage"@.
: Note that if you change this, you will have to generate your own custom newer/older links (see "example 11":#eg11) and the "conditional tags":#smd_qif.
: You may also use the special value @pagevar="SMD_QUERY_UNIQUE_ID"@ which will assign the paging variable to this specific instance of your query. This will allow you to use multiple smd_query tags on a single page and navigate them independently using the same @pageform@ (see "example 12":#eg12 for details).
: Default: @pg@.

h4. Filters

; %urlfilter%
: Filter URL input with this list of regular expressions (each separated by @delim@).
; %urlreplace%
: Replace each filtered URL element listed in @urlfilter@ with this list of regular expressions (each separated by @delim@).
: If not used, anything matching @urlfilter@ will be removed from any URL variables. See "Filtering and injection":#filtering.
; %defaults%
: Comma separated list of values to use in the event some field you specified doesn't exist. Each default should be given as @name: value@ (the @:@ is configurable via the @paramdelim@ attribute).
: For example @defaults="id: 1, my_cat: mammals, user_sec: ?defsec"@ would mean that if the @id@ field was blank, the number 1 would be used; if the variable @my_cat@ was empty, the word @mammals@ would be used; and if the @user_sec@ variable was empty, use the default value as found in the variable @defsec@ (which could have been set via a @<txp:variable />@ earlier in the page).
; %escape%
: List of column names with which to escape HTML entities. Useful if you have returned body or excerpt blocks that may contain apostrophes that could kill tags inside the smd_query container.

h4. Tag/class/formatting attributes

; %label%
: Label to display above the entire output.
; %labeltag%
: The HTML tag to surround the label with. Specify it without angle brackets (e.g. @labeltag="h3"@).
; %wraptag%
: The HTML tag to surround the entire output (e.g. @wraptag="table"@).
; %html_id%
: HTML ID to apply to the wraptag.
; %class%
: CSS class name to apply to the @wraptag@.
; %break%
: Each returned row of data will be wrapped with this tag (e.g. @break="tr"@).
; %breakclass%
: CSS class name to apply to the @break@ tag.

h4. Plugin customisation

; %strictfields%
: When using '?' fields, spaces are allowed in field names. Set @strictfields="1"@ to forbid spaces.
: Default: 0.
; %delim%
: The delimiter to use between patterns in @urlfilter@ and @urlreplace@.
: Default: @,@ (comma).
; %paramdelim%
: The delimiter to use between name-value pairs in @defaults@.
: Default: @:@ (colon)
; %hashsize%
: (should not be needed) The plugin assigns a 32-character, unique reference to the current smd_query based on your query attributes. @hashsize@ governs the mechanism for making this long reference shorter.
: It comprises two numbers separated by a colon; the first is the length of the unique ID, the second is how many characters to skip past each time a character is chosen. For example, if the unique_reference was @0cf285879bf9d6b812539eb748fbc8f6@ then @hashsize="6:5"@ would make a 6-character unique ID using every 5th character; in other words @05f898@.
: If at any time, you "fall off" the end of the long string, the plugin wraps back to the beginning of the string and continues counting.
: Default: @6:5@.
; %silent% : if your query contains an error (wrong column name or some malformed input), the plugin will issue a Txp or SQL error message. Using @silent="1"@ will attempt to hide this error message.
; %var_prefix%
: The prefix to use for the page and counting {replacement} variables.
: Default: @smd_@.
; %count%
: Can be either "up" or "down" to alter the order the plugin counts row numbers. See "{smd_thisrow}":#replacements.
: Default: @up@.
; %raw_vals%
: Whether to expose raw values or processed values to the replacement/container tags.
: Default: 0 (use sanitized values where possible)
; %debug%
: Set to 1 to show some debug output; use 2 to show a bit more detail.

The attributes @query@, @column@, @table@ and @where@ can contain replacements themselves to read values from the current context. Specify the field name with a @?@ in front of it (e.g. @query="SELECT * FROM txp_image WHERE category='?category1' OR category='?category2'@) would show images that had their category set to one of the article's categories.

The '?' fields can be any item from the Textpattern universe, including anything set in a @<txp:variable />@ or some user-input on the URL address bar. Fields are processed in the following order; as soon as a matching entry is found, the rest are not checked:

# @<txp:variable />@
# @$_SERVER@ var
# image
# file
# link
# global article
# current article
# URL var
# default value
# verbatim (without '?')

This hierarchy allows some degree of safety: since Txp variables are ultimately set by you, they are checked first, then gradually less specific stuff is checked until URL variables are considered at the bottom of the food chain.

h2(#replacements). Replacement tags

In your output form you may specify any column name surrounded with @{}@ characters to display that field. So if your query was @SELECT id, name, category FROM txp_image WHERE ext=".jpg"@ you would have the following replacements available:

* %{id}% : the image ID
* %{name}% : the image filename
* %{category}% : the image category

Just put those names into your @form@ among other normal HTML or TXP tags, and the relevant value from that row will be displayed. The replacements honour any @AS@ statement you may employ to rename them. If you wish to use replacements as attributes to other Txp tags, you should use single _not_ double quotes. These uses are all fine:

bc. {custom_5}
<span>{custom_5}</span>
<txp:custom_field name='{custom_5}' />

whereas this will probably not work as expected:

bc. <txp:custom_field name="{custom_5}" />

In addition to the replacements from your query, the following replacements are added to each row (note that the 'smd_' prefix can be altered with the @var_prefix@ attribute):

* %{smd_allrows}% : the total number of rows in this result set[1]
* %{smd_pages}% : the total number of pages in this result set[1]
* %{smd_thispage}% : the current page number being viewed[1]
* %{smd_rows}% : the total number of rows visible on this page
* %{smd_thisrow}% : the current row number on this page
* %{smd_thisindex}% : the current row number (zero-based) on this page
* %{smd_cursor}% : the current row number from the start of the result set
* %{smd_cursorindex}% : the current row number from the start of the result set (zero-based)
* %{smd_usedrow}% : the current row number of rows that have actually been displayed. If your smd_query container employed a conditional, for example, that only output certain rows then this counter only increments for those rows you display. Bear in mind that using conditionals inside smd_query can mess paging and other counters up, so it's best to avoid paging if you are using conditional output.

fn1. These items are also available in your designated @pageform@. The pageform can also utilise these extra replacements:

* %{smd_prevpage}% : the previous page number (empty if on first page)
* %{smd_nextpage}% : the next page number (empty if on last page)
* %{smd_row_start}% : the first row number being displayed
* %{smd_row_end}% : the last row number being displayed
* %{smd_rows_prev}% : the number of rows on the previous page. Will either be the value of your @limit@, or 0 if on the first page
* %{smd_rows_next}% : the number of rows on the next page
* %{smd_query_unique_id}% : the unique ID assigned to this smd_query tag (see the @hashsize@ attribute and "example 12":#eg12 for more)

These are useful for tables to show row numbers, but can also be used for pagination or can be tested with smd_if to take action from within your form. @{smd_thisrow}@, @{smd_thisindex}@, @{smd_cursor}@, and @{smd_cursorindex}@ count up or down depending on the @count@ attribute (@{smd_row_start}@ and @{smd_row_end}@ also change accordingly).

h2(#smd_qinfo). Tag: @<txp:smd_query_info>@

While @{replacements}@ are handy, sometimes a real tag is better. smd_query gives you the choice, because there are sometimes advantages to using a tag over replacements. The primary reason is that you can list items of the same type that you want, then use the @wraptag@, @break@ and @class@ attributes to wrap and separate them. This can result in shorter code and may save having to use a conditional tag around optional data.

The tag's attributes are:

; %type%
: The type of data you want to display. Choose from:
:: @field@ for standard field information, e.g. named columns from the database
:: @page@ for page-based information if you are using paging (some paging info is available in the @field@ type, as noted in the "replacements":#replacements section)
:: @col@ for column heading information
: Default: @field@
; %item%
: Comma-separated list of items you want to display. *They are case-sensitive*.
: Can be any column name you have elected to pull from the database or a paging variable, but they must all be of the same @type@ (use the @debug="1"@ attribute to see a list of all available items from each type).
; %wraptag%
: The HTML tag to surround the entire list.
; %class%
: CSS class name to apply to the @wraptag@.
; %break%
: Each returned item of data will be wrapped with this tag, or if a tag is not used the given text will be put between each item.

h2(#smd_qif). Tags: @<txp:smd_query_if_prev> / <txp:smd_query_if_next>@

Use these container tags to determine if there is a next or previous page and take action if so. Can only be used inside @pageform@, thus all "paging replacement variables":#replacements are available inside these tags.

bc. <txp:smd_query_if_prev>Previous page</txp:smd_query_if_prev>
<txp:smd_query_if_next>Next page</txp:smd_query_if_next>

See "example 11":#eg11 for more.

h2(#filtering). Filtering and injection

After great deliberation, access to the URL line has been granted so you may employ user-entered data in your queries, allowing complete flexibility for your user base. However, as Peter Parker's conscience might say:

bq. With great power comes great responsibility.

Not everybody out there is trustworthy so heed this warning: *Assume ALL user input is tainted*. Check everything. If you want to know more about what people can do with access to one simple portion of your SQL query, Google for 'SQL injection'.

For those still reading, the good news is that the plugin does everything it can to pre-filter stuff on the URL line before it gets to the query. This should make your user input safe enough, but for the paranoid (or sensible) there are two attributes you can use to clamp down allowable user input. If you know anything about "regular expressions":http://www.regular-expressions.info/quickstart.html or are familiar with the PHP function "preg_replace()":http://uk2.php.net/preg_replace then you'll be right at home because, put simply, you can optionally pass every URL variable through it to remove stuff you don't want.

h3(#urlfilter). urlfilter

This takes a comma-separated list (at least by default; override the comma with the @delim@ attribute if you need to use commas in your filter strings) of complete regular expression patterns that you wish to search for, in every URL variable. For example, if you wanted to ensure that your users only entered digits you could specify this:

bc. urlfilter="/[^\d]+/"

Briefly, the starting and trailing @/@ marks delimit a regular expression -- they must always be present. The square brackets denote a group of characters, the circumflex negates the group, the @\d@ means "any digit" and the @+@ specifies that you want it to check for one or more of the preceding things. In other words, look for anything in the input that is *not* one or more digits. That would match any letters, quotes, special characters, anything at all that wasn't a zero to nine.

You can specify more than one filter like this:

bc. urlfilter="/\d/, /\s/"

That would look for any single digit and any single space character. That's a simple example and you could do it all in one regex, but splitting them up can help you filter stuff better (see "urlreplace":#urlreplace for an example).

By default, if you just specify @urlfilter@ without @urlreplace@, anything that matches your filter patterns will be removed from the user input.

h3(#urlreplace). urlreplace

The other half of the filtering jigsaw allows you to not just remove anything that matches, but actually replace it with something else. Specify a fixed string, a list of fixed strings or more URL patterns to replace whatever matches your @urlfilter@. Using the first filter example from above, you could replace anything that is not a digit with a hyphen by specifying:

bc. urlreplace="-"

So if you allowed a URL variable called @digits@ and a site visitor entered @?digits=Zaphod 4 Trillian@, your URL variable would become: @-------4--------@. Not much use, but hey, it's an example!

As with @urlfilter@ you can specify more than one replacement and they will pair up with their corresponding filter. In other words, if you take the second filter above (@urlfilter="/\d/, /\s/"@) and used this:

bc. urlreplace=", -"

Then any digit in your user input would be removed (there is nothing before the comma) and any space character would be replaced with a hyphen.

If at any time a field gives an empty result (i.e. it totally fails any @urlfilter@ tests or simply returns nothing because it has not been set), any @defaults@ assigned to that variable will be used instead. If there are no defaults, the name of the variable itself (minus its @?@) will be used.

With these two filters at your disposal and the ability to specify default values for user variables, you can make your queries much safer to the outside world and start using HTML forms to gather input from users that can then be plugged into queries, fairly safe in the knowledge that your database is not going to implode.

But please remember:

bq. Assume *all* user input is tainted: check everything.

h2(#examples). Examples

h3(#eg1). Example 1: Simple image select query

bc. <txp:smd_query column="*"
     table="txp_image"
     where="category='mammal' OR category='bird'"
     form="dbout" wraptag="ul" break="li" />

With form @dbout@ containing:

bc. <a href="/images/{id}{ext}" /><txp:thumbnail name="{name}" /></a>

Will render an unordered list of thumbnails with links to the fullsize image if the category is either @mammal@ or @bird@.

h3(#eg2). Example 2: link category list to parent

bc. <txp:smd_query query="SELECT DISTINCT
     txc.name FROM txp_category AS txc, textpattern AS txp
     WHERE type='article' AND parent='animals'
     AND (txc.name = txp.category1 OR txc.name = txp.category2)
     form="dbout" wraptag="ul" break="li" />

With form @dbout@ containing:

bc. <txp:category name="{name}" link="1" title="1" />

Will render a list of linkable category names that contain articles with categories that have the given parent. If a category is unused it will not be listed.

h3(#eg3). Example 3: child category counts

bc. <txp:smd_query query="SELECT DISTINCT
     txc.name, COUNT(*) AS count FROM txp_category AS txc,
     textpattern AS txp
     WHERE type='article' AND parent='?custom3'
     AND (txc.name = txp.category1 OR txc.name = txp.category2)
     GROUP BY txc.name"
     form="dbout" wraptag="ul" break="li" />

With form @dbout@ containing:

bc. <txp:category name="{name}" link="1" title="1" /> ({count})

Will read the parent item from the @custom3@ field and render a similar list to Example 2 but with the article counts added in parentheses afterwards.

h3(#eg4). Example 4: Top 10 downloads

bc. <txp:smd_query column="*" table="txp_file"
     where="(category='?category1' OR category='?category2')
     AND status=4 ORDER BY downloads desc LIMIT 10"
     wraptag="table" break="tr"
     label="Most popular downloads" labeltag="h3">
  <td>
    <txp:file_download_link id="{id}">
      <txp:smd_query_info item="filename" />
    </txp:file_download_link></td>
  <td>
    <txp:smd_query_info item="description" />
  </td>
  <td>downloads: {downloads}</td>
<txp:else />
  <p>No recent downloads, sorry</p>
</txp:smd_query>

This one uses the plugin as a container tag instead of a form and tabulates the top 10 downloads (status=live) that have a category matching either of the current article's categories, with most popular listed first. If there are no downloads, the @<txp:else />@ portion displays a message. Note that it also uses @<txp:smd_query_info />@ to display user-uploaded information as we don't implicitly trust our users not to put rogue filenames / descriptions in.

h3(#eg5). Example 5: Article keywords related to link

Very interesting use case here. Put this in the plainlinks form:

bc. <txp:linkdesctitle />
<txp:smd_query query="SELECT DISTINCT
     txp.id, txp.title FROM textpattern AS txp
     WHERE (txp.keywords LIKE '%,?category%,'
     OR txp.keywords LIKE '%?category%,'
     OR txp.keywords LIKE '%,?category%')
     GROUP BY txp.title"
     wraptag="ul" break="li">
  <txp:permlink id="{id}">
    <txp:smd_query_info item="title" />
  </txp:permlink>
</txp:smd_query>

When you execute @<txp:linklist />@ from a page you will get a list of links as usual, but under each one you will see a hyperlinked list of articles that are related (by keyword) to the category of the link.

The reason it is compared three times is because article keywords are stored like this in the database:

@government,conspiracy,id,card,data,biometric,bad,idea@

If each category word was compared only once without commas (i.e. @txp.keywords LIKE '%?category%'@) then a link with category @piracy@ would cause any article containing keyword @conspiracy@ to be included. By comparing the category either surrounded by commas, with a comma after it, or with a comma before it, the search is restricted to only match whole words.

h3(#eg6). Example 6: Comparison in queries

bc. <txp:smd_query query="SELECT *
     FROM txp_file WHERE downloads &gt;= 42">
  <txp:file_download_link id="{id}">
     <txp:smd_query_info item="filename" />
  </txp:file_download_link>
</txp:smd_query>

Shows links to all downloads where the download count is greater than or equal to 42. Note that under TXP 4.0.6 and below you must use the HTML entity names for @&gt;@ and @&lt;@ or the parser gets confused.

h3(#eg7). Example 7: unfiltered URL params (bad)

(a bad query)

bc. <txp:variable name="cutoff"
     value="42" />
<txp:smd_query query="SELECT Title
     FROM textpattern
     WHERE id < '?usercut'"
     defaults="usercut: ?cutoff">
   <txp:permlink>
      <txp:smd_query_info item="Title" />
   </txp:permlink>
</txp:smd_query>

Shows hyperlinks to only those articles with an ID below the number given by the user on the URL line. If the value is not supplied, the default value from the TXP variable is used instead (42 in this case).

Notes:

* validation is not performed and you cannot guarantee that the @usercut@ variable is going to be numeric. You should not use this query on a production site unless you add a @urlfilter@ to remove any non-numeric characters (see next example for a better query).
* the item name is case-sensitive in the @<txp:smd_query_info />@ tag

h3(#eg8). Example 8: filtered URL params (better!)

bc. <txp:smd_query query="SELECT Title
     FROM textpattern
     WHERE status = '?user_status'"
     urlfilter="/[^1-5]/"
     defaults="user_status: 4">
   <txp:permlink>
      <txp:smd_query_info item="Title" />
   </txp:permlink>
</txp:smd_query>

Pulls all articles out of the database that match the given status. This is a more robust query than Example 7 because it checks if the @user_status@ field is 1, 2, 3, 4, or 5 (the regex specifies to remove everything from the user_status variable that is not in the range 1-5). If this condition is not met -- e.g. the user specifies @user_status=6@ or @user_status="abc"@ -- then user_status will be set to @4@. Note that using @user_status="Zaphod 4 Trillian"@ on the URL address bar will actually pass the test because all characters other than the number '4' will be removed.

You could use a @<txp:variable />@ if you wish and set all your defaults in a special form, ready to use throughout your page. In that case -- if you had created a variable called @dflt_stat@ -- you might prefer to use @defaults="user_status: ?dflt_stat"@.

Query-tastic :-)

h3(#eg9). Example 9: Using preparse with @<txp:yield />@

Sometimes you may want to re-use a query in a few places throughout your site and show different content. For example, the same query could be used for logged-in and not-logged-in users but you'd see more detail if you were logged in. Normally you would need to write the query more than once, which is far from ideal. This technique allows you to write the query just once and reuse the form. Put this in a form called @user_table@:

bc. <txp:smd_query query="SELECT * FROM txp_users"
     wraptag="table" break="tr" preparse="1">
   <txp:yield />
</txp:smd_query>

Using @<txp:output_form>@ as a container (in Txp 4.2.0 or higher) you can then call the query like this to show basic info:

bc. <txp:output_form form="user_table">
<td>{name}</td>
<td>{RealName}</td>
</txp:output_form>

and like this for more detailed output:

bc. <txp:output_form form="user_table">
<td>{name}</td>
<td>{RealName}</td>
<td>{email}</td>
<td>{last_access}</td>
</txp:output_form>

Note that when using smd_query in this manner you must remember to use @preparse="1"@ because you need to fetch the contents of the smd_query container (the @<txp:yield />@ tag in this case), parse it so it gets the contents of @<txp:output_form>@'s container and _then_ applies the replacements. Without the @preparse@, the plugin tries to apply the replacements directly to the smd_query container, which does not actually contain any @{...}@ tags.

h3(#eg10). Example 10: pagination

Iterate over some Txp user information, 5 people at a time:

bc. <txp:smd_query query="SELECT * from txp_users"
     limit="5" wraptag="ul" break="li"
     pageform="page_info">
   <txp:smd_query_info item="RealName" /> ({privs})
</txp:smd_query>

In @page_info@:

bc. Page {smd_thispage} of {smd_pages} |
Showing records {smd_row_start} to {smd_row_end}
of {smd_allrows} |
<txp:older>Next {smd_rows_next}</txp:older> |
<txp:newer>Previous {smd_rows_prev}</txp:newer>

Underneath your result set you would then see the information regarding which page and rows your visitors were currently viewing. You would also see next/prev links to the rest of the results.

h3(#eg11). Example 11: custom pagination

There is a problem with "example 10":#eg10 ; if you use txp:older and txp:newer when you are showing a standard article list; the paging tags will step through _both_ your result set and your articles. To break the association between them you need to alter the variable that TXP uses to control paging. It is called @pg@and you'll notice it in the URL (@?pg=2@ for example) as you step through article lists.

Using the @pagevar@ attribute you can tell smd_query to watch for your own variable instead of the default @pg@ and thus build your own next/prev links that only control smd_query.

bc. <txp:smd_query query="SELECT * from txp_users"
     limit="5" wraptag="ul" break="li"
     pageform="page_info" pagevar="smd_qpg">
   <txp:smd_query_info item="RealName" /> ({privs})
</txp:smd_query>

In @page_info@:

bc. Page {smd_thispage} of {smd_pages} |
   Showing rows {smd_row_start}
   to {smd_row_end} of {smd_allrows} |
<txp:smd_query_if_prev>
  <a href="<txp:permlink />?smd_qpg={smd_prevpage}">
     Previous {smd_rows_prev}</a>
</txp:smd_query_if_prev>
<txp:smd_query_if_next>
  <a href="<txp:permlink />?smd_qpg={smd_nextpage}">
     Next {smd_rows_next}</a>
</txp:smd_query_if_next>

Or using the @<txp:smd_query_info />@ tag in a few places (which is probably overkill in this case and is just for demonstration of the @type@ attribute):

bc. Page
 <txp:smd_query_info item="smd_thispage, smd_pages" break=" of " /> |
Showing rows <txp:smd_query_info type="page"
     item="smd_row_start, smd_row_end" break=" to " />
of <txp:smd_query_info item="smd_allrows" /> |
<txp:smd_query_if_prev>
  <a href="<txp:permlink />?smd_qpg={smd_prevpage}">
     Previous {smd_rows_prev}</a>
</txp:smd_query_if_prev>
<txp:smd_query_if_next>
  <a href="<txp:permlink />?smd_qpg={smd_nextpage}">
     Next {smd_rows_next}</a>
</txp:smd_query_if_next>

h3(#eg12). Example 12: using @SMD_QUERY_UNIQUE_ID@

If you wish to use more than one smd_query on a single page but share a pageform between them you can use SMD_QUERY_UNIQUE_ID as the paging variable:

bc. <txp:smd_query query="SELECT * from txp_users"
     limit="5" wraptag="ul" break="li"
     pageform="page_info"
     pagevar="SMD_QUERY_UNIQUE_ID">
   <txp:smd_query_info item="RealName" /> ({privs})
</txp:smd_query>

In @page_info@:

bc. Page {smd_thispage} of {smd_pages} |
   Showing records {smd_row_start}
   to {smd_row_end} of {smd_allrows} |
<txp:smd_query_if_prev>
  <a href="<txp:permlink />?{smd_query_unique_id}={smd_prevpage}">
     Previous {smd_rows_prev}</a>
</txp:smd_query_if_prev>
<txp:smd_query_if_next>
  <a href="<txp:permlink />?{smd_query_unique_id}={smd_nextpage}">
     Next {smd_rows_next}</a>
</txp:smd_query_if_next>

Note this is just a simple example: you will have to be more clever than that if you are paging independent sets of rows because you will need to incorporate the paging variable from both smd_query tags in your pageform.

h3(#eg13). Example 13: Txp tags in container

bc. ==<txp:smd_query query="SELECT *,
     unix_timestamp(Posted) as uPosted,
     unix_timestamp(LastMod) as uLastMod,
     unix_timestamp(Expires) as uExpires
     FROM textpattern WHERE Status IN (4,5)"
     wraptag="ul" break="li" html_id="myQuery"
     populate="article">
   <txp:title /> [ <txp:posted /> ]
</txp:smd_query>==

Note that in versions of TXP earlier than 4.3.0, the @populate@ attribute relies on you extracting *all* columns to satisfy textpattern's internal functions so this feature works correctly. A simple @select * from ...@ will not work. In Txp 4.3.0 and higher you can omit the @unix_timestamp()@ columns.

For reference, these are the extra columns required in 4.2.0 (and earlier):

* Article: @unix_timestamp(Posted) as uPosted, unix_timestamp(LastMod) as uLastMod, unix_timestamp(Expires) as uExpires@
* Image: none
* File: none
* Link: @unix_timestamp(date) as uDate@

h3(#eg14). Example 14: @<txp:else />@ with forms

If you wish to use txp tags with an 'else' clause, you usually need to employ a container. As a convenience, smd_query allows you to use the container's @<txp:else />@ clause with a form so you can re-use the query output and display different results in the event the query returns nothing.

bc. <txp:smd_query query="SELECT * FROM txp_users"
     form="show_users">
<txp:else />
<p>No user info</p>
</txp:smd_query>

Your @show_users@ form can contain usual replacement variables, tags and markup to format the results. Perhaps later you may wish to re-use the show_users output in another query:

bc. ==<txp:smd_query query="SELECT * FROM txp_users WHERE
     RealName like '%?usr%'" form="show_users">
<txp:else />
<p>No matching users found</p>
</txp:smd_query>==

Note that you can display a different error message but use the same form (we're escaping Textile here with @==@ so it doesn't interpret the percent signs as @<span>@ elements).

If you are careful and know you will _never_ use a particular form with an smd_query container you can hard-code your 'else' clause directly in your form and use smd_query as a self-closing tag. Your form will look a bit odd with a seeming 'dangling' else, but it will work due to the way the TXP parser operates. If you do try and use a form with a @<txp:else />@ in it as well as calling the form using an smd_query with a @<txp:else />@ in its container, Textpattern will throw an error (usually the, perhaps unexpected, @tag does not exist@ error). Be careful!

h2. Author

"Stef Dawson":https://stefdawson.com/contact

# --- END PLUGIN HELP ---
-->
<?php
}
?>
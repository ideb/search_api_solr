<?php

namespace Drupal\search_api_solr\Utility;

use Drupal\Component\Utility\NestedArray;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\ServerInterface;
use Drupal\search_api_solr\SearchApiSolrException;
use Drupal\search_api_solr\SolrBackendInterface;
use Drupal\search_api_solr\SolrFieldTypeInterface;
use Solarium\Core\Client\Request;

/**
 * Provides various helper functions for Solr backends.
 */
class Utility {

  /**
   * Retrieves Solr-specific data for available data types.
   *
   * Returns the data type information for the default Search API datatypes, the
   * Solr specific data types and custom data types defined by
   * hook_search_api_data_type_info().
   * Names for default data types are not included, since they are not relevant
   * to the Solr service class.
   *
   * We're adding some extra Solr field information for the default search api
   * data types (as well as on behalf of a couple contrib field types). The
   * extra information we're adding is documented in
   * search_api_solr_hook_search_api_data_type_info(). You can use the same
   * additional keys in hook_search_api_data_type_info() to support custom
   * dynamic fields in your indexes with Solr.
   *
   * @param string|null $type
   *   (optional) A specific type for which the information should be returned.
   *   Defaults to returning all information.
   *
   * @return array|null
   *   If $type was given, information about that type or NULL if it is unknown.
   *   Otherwise, an array of all types. The format in both cases is the same as
   *   for search_api_get_data_type_info().
   *
   * @see search_api_get_data_type_info()
   * @see search_api_solr_hook_search_api_data_type_info()
   */
  public static function getDataTypeInfo($type = NULL) {
    $types = &drupal_static(__FUNCTION__);

    if (!isset($types)) {
      // Grab the stock search_api data types.
      /** @var \Drupal\search_api\DataType\DataTypePluginManager $data_type_service */
      $data_type_service = \Drupal::service('plugin.manager.search_api.data_type');
      $types = $data_type_service->getDefinitions();

      // Add our extras for the default search api fields.
      $types = NestedArray::mergeDeep($types, [
        'text' => [
          'prefix' => 't',
        ],
        'string' => [
          'prefix' => 's',
        ],
        'integer' => [
          // Use trie field for better sorting.
          'prefix' => 'it',
        ],
        'decimal' => [
          // Use trie field for better sorting.
          'prefix' => 'ft',
        ],
        'date' => [
          'prefix' => 'd',
        ],
        'duration' => [
          // Use trie field for better sorting.
          'prefix' => 'it',
        ],
        'boolean' => [
          'prefix' => 'b',
        ],
        'uri' => [
          'prefix' => 's',
        ],
      ]);

      // Extra data type info.
      $extra_types_info = [
        // Provided by Search API Location module.
        'location' => [
          'prefix' => 'loc',
        ],
        // @todo Who provides that type?
        'geohash' => [
          'prefix' => 'geo',
        ],
        // Provided by Search API Location module.
        'rpt' => [
          'prefix' => 'rpt',
        ],
      ];

      // For the extra types, only add our extra info if it's already been
      // defined.
      foreach ($extra_types_info as $key => $info) {
        if (array_key_exists($key, $types)) {
          // Merge our extras into the data type info.
          $types[$key] += $info;
        }
      }
    }

    // Return the info.
    if (isset($type)) {
      return $types[$type] ?? NULL;
    }
    return $types;
  }

  /**
   * Returns a unique hash for the current site.
   *
   * This is used to identify Solr documents from different sites within a
   * single Solr server.
   *
   * @return string
   *   A unique site hash, containing only alphanumeric characters.
   */
  public static function getSiteHash() {
    // Copied from apachesolr_site_hash().
    if (!($hash = \Drupal::state()->get('search_api_solr.site_hash', FALSE))) {
      global $base_url;
      $hash = substr(base_convert(sha1(uniqid($base_url, TRUE)), 16, 36), 0, 6);
      \Drupal::state()->set('search_api_solr.site_hash', $hash);
    }
    return $hash;
  }

  /**
   * Retrieves a list of all config files of a server's Solr backend.
   *
   * @param \Drupal\search_api\ServerInterface $server
   *   The Solr server whose files should be retrieved.
   * @param string $dir_name
   *   (optional) The directory that should be searched for files. Defaults to
   *   the root config directory.
   *
   * @return array
   *   An associative array of all config files in the given directory. The keys
   *   are the file names, values are arrays with information about the file.
   *   The files are returned in alphabetical order and breadth-first.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   If a problem occurred while retrieving the files.
   */
  public static function getServerFiles(ServerInterface $server, $dir_name = NULL) {
    /** @var \Drupal\search_api_solr\SolrBackendInterface $backend */
    $backend = $server->getBackend();
    $response = $backend->getSolrConnector()->getFile($dir_name);

    // Search for directories and recursively merge directory files.
    $files_data = json_decode($response->getBody(), TRUE);
    $files_list = $files_data['files'];
    $dir_length = strlen($dir_name) + 1;
    $result = ['' => []];

    foreach ($files_list as $file_name => $file_info) {
      // Annoyingly, Solr 4.7 changed the way the admin/file handler returns
      // the file names when listing directory contents: the returned name is
      // now only the base name, not the complete path from the config root
      // directory. We therefore have to check for this case.
      if ($dir_name && substr($file_name, 0, $dir_length) !== "$dir_name/") {
        $file_name = "$dir_name/" . $file_name;
      }
      if (empty($file_info['directory'])) {
        $result[''][$file_name] = $file_info;
      }
      else {
        $result[$file_name] = static::getServerFiles($server, $file_name);
      }
    }

    ksort($result);
    ksort($result['']);
    return array_reduce($result, 'array_merge', []);
  }

  /**
   * Returns the highlighted keys from a snippet highlighted by Solr.
   *
   * @param string|array $snippets
   *   The snippet(s) to format.
   *
   * @return array
   *   The highlighted keys.
   */
  public static function getHighlightedKeys($snippets) {
    if (is_string($snippets)) {
      $snippets = [$snippets];
    }

    $keys = [];

    foreach ($snippets as $snippet) {
      if (preg_match_all('@\[HIGHLIGHT\](.+?)\[/HIGHLIGHT\]@', $snippet, $matches)) {
        $keys[] = $matches[1];
      }
    }

    return array_unique(array_merge(...$keys));
  }

  /**
   * Changes highlighting tags from our custom, HTML-safe ones to HTML.
   *
   * @param string|array $snippet
   *   The snippet(s) to format.
   * @param string|array $prefix
   *   (optional) The opening tag to replace "[HIGHLIGHT]".
   *   Defaults to "<strong>".
   * @param string|array $suffix
   *   (optional) The closing tag to replace "[/HIGHLIGHT]".
   *   Defaults to "</strong>".
   *
   * @return string|array
   *   The snippet(s), properly formatted as HTML.
   */
  public static function formatHighlighting($snippet, $prefix = '<strong>', $suffix = '</strong>') {
    return str_replace(['[HIGHLIGHT]', '[/HIGHLIGHT]'], [$prefix, $suffix], $snippet);
  }

  /**
   * Encodes field names to avoid characters that are not supported by solr.
   *
   * Solr doesn't restrict the characters used to build field names. But using
   * non java identifiers within a field name can cause different kind of
   * trouble when running queries. Java identifiers are only consist of
   * letters, digits, '$' and '_'. See
   * https://issues.apache.org/jira/browse/SOLR-3996 and
   * http://docs.oracle.com/cd/E19798-01/821-1841/bnbuk/index.html
   * For full compatibility the '$' has to be avoided, too. And there're more
   * restrictions regarding the field name itself. See
   * https://cwiki.apache.org/confluence/display/solr/Defining+Fields
   * "Field names should consist of alphanumeric or underscore characters only
   * and not start with a digit ... Names with both leading and trailing
   * underscores (e.g. _version_) are reserved." Field names starting with
   * digits or underscores are already avoided by our schema. The same is true
   * for the names of field types. See
   * https://cwiki.apache.org/confluence/display/solr/Field+Type+Definitions+and+Properties
   * "It is strongly recommended that names consist of alphanumeric or
   * underscore characters only and not start with a digit. This is not
   * currently strictly enforced."
   *
   * This function therefore encodes all forbidden characters in their
   * hexadecimal equivalent encapsulated by a leading sequence of '_X' and a
   * termination character '_'. Example:
   * "tm_entity:node/body" becomes "tm_entity_X3a_node_X2f_body".
   *
   * As a consequence the sequence '_X' itself needs to be encoded if it occurs
   * within a field name. Example: "last_XMas" becomes "last_X5f58_Mas".
   *
   * @param string $field_name
   *   The field name.
   *
   * @return string
   *   The encoded field name.
   */
  public static function encodeSolrName($field_name) {
    return preg_replace_callback('/([^\da-zA-Z_]|_X)/u',
      function ($matches) {
        return '_X' . bin2hex($matches[1]) . '_';
      },
      $field_name);
  }

  /**
   * Decodes solr field names.
   *
   * This function therefore decodes all forbidden characters from their
   * hexadecimal equivalent encapsulated by a leading sequence of '_X' and a
   * termination character '_'. Example:
   * "tm_entity_X3a_node_X2f_body" becomes "tm_entity:node/body".
   *
   * @param string $field_name
   *   Encoded field name.
   *
   * @return string
   *   The decoded field name
   *
   * @see \Drupal\search_api_solr\Utility\Utility::modifySolrDynamicFieldName()
   */
  public static function decodeSolrName($field_name) {
    return preg_replace_callback('/_X([\dabcdef]+?)_/',
      function ($matches) {
        return hex2bin($matches[1]);
      },
      $field_name);
  }

  /**
   * Maps a Solr field name to its language-specific equivalent.
   *
   * For example the dynamic field tm_* will become tm;en* for English.
   * Following this pattern we also have fall backs automatically:
   * - tm;de-AT_*
   * - tm;de_*
   * - tm_*
   * This concept bases on the fact that "longer patterns will be matched first.
   * If equal size patterns both match,the first appearing in the schema will be
   * used." This is not obvious from the example above. But you need to take
   * into account that the real field name for solr will be encoded. So the real
   * values for the example above are:
   * - tm_X3b_de_X2d_AT_*
   * - tm_X3b_de_*
   * - tm_*
   *
   * @param string $field_name
   *   The field name.
   * @param string $language_id
   *   The Drupal language code.
   *
   * @return string
   *   The language-specific name.
   *
   * @see \Drupal\search_api_solr\Utility\Utility::encodeSolrName()
   * @see https://wiki.apache.org/solr/SchemaXml#Dynamic_fields
   */
  public static function getLanguageSpecificSolrDynamicFieldNameForSolrDynamicFieldName($field_name, $language_id) {
    if ('twm_suggest' === $field_name) {
      return 'twm_suggest';
    }

    return Utility::modifySolrDynamicFieldName($field_name, '@^([a-z]+)_@', '$1' . SolrBackendInterface::SEARCH_API_SOLR_LANGUAGE_SEPARATOR . $language_id . '_');
  }

  /**
   * Maps a language-specific Solr field name to its unspecific equivalent.
   *
   * For example the dynamic field tm;en_* for English will become tm_*.
   *
   * @param string $field_name
   *   The field name.
   *
   * @return string
   *   The language-unspecific field name.
   *
   * @see \Drupal\search_api_solr\Utility\Utility::getLanguageSpecificSolrDynamicFieldNameForSolrDynamicFieldName()
   * @see \Drupal\search_api_solr\Utility\Utility::encodeSolrName()
   * @see https://wiki.apache.org/solr/SchemaXml#Dynamic_fields
   */
  public static function getSolrDynamicFieldNameForLanguageSpecificSolrDynamicFieldName($field_name) {
    return Utility::modifySolrDynamicFieldName($field_name, '@^([a-z]+)' . SolrBackendInterface::SEARCH_API_SOLR_LANGUAGE_SEPARATOR . '[^_]+?_@', '$1_');
  }

  /**
   * Modifies a dynamic Solr field's name using a regular expression.
   *
   * If the field name is encoded it will be decoded before the regular
   * expression runs and encoded again before the modified is returned.
   *
   * @param string $field_name
   *   The dynamic Solr field name.
   * @param string $pattern
   *   The regex.
   * @param string $replacement
   *   The replacement for the pattern match.
   *
   * @return string
   *   The modified dynamic Solr field name.
   *
   * @see \Drupal\search_api_solr\Utility\Utility::encodeSolrName()
   */
  protected static function modifySolrDynamicFieldName($field_name, $pattern, $replacement) {
    $decoded_field_name = Utility::decodeSolrName($field_name);
    $modified_field_name = preg_replace($pattern, $replacement, $decoded_field_name);
    if ($decoded_field_name != $field_name) {
      $modified_field_name = Utility::encodeSolrName($modified_field_name);
    }
    return $modified_field_name;
  }

  /**
   * Gets the language-specific prefix for a dynamic Solr field.
   *
   * @param string $prefix
   *   The language-unspecific prefix.
   * @param string $language_id
   *   The Drupal language code.
   *
   * @return string
   *   The language-specific prefix.
   */
  public static function getLanguageSpecificSolrDynamicFieldPrefix($prefix, $language_id) {
    return $prefix . SolrBackendInterface::SEARCH_API_SOLR_LANGUAGE_SEPARATOR . $language_id . '_';
  }

  /**
   * Extracts the language code from a language-specific dynamic Solr field.
   *
   * @param string $field_name
   *   The language-specific dynamic Solr field name.
   *
   * @return mixed
   *   The Drupal language code as string or boolean FALSE if no language code
   *   could be extracted.
   */
  public static function getLanguageIdFromLanguageSpecificSolrDynamicFieldName($field_name) {
    $decoded_field_name = Utility::decodeSolrName($field_name);
    if (preg_match('@^[a-z]+' . SolrBackendInterface::SEARCH_API_SOLR_LANGUAGE_SEPARATOR . '([^_]+?)_@', $decoded_field_name, $matches)) {
      return $matches[1];
    }
    return FALSE;
  }

  /**
   * Extracts the language-specific definition from a dynamic Solr field.
   *
   * @param string $field_name
   *   The field name.
   *
   * @return mixed
   *   The language-specific prefix as string or boolean FALSE if no prefix
   *   could be extracted.
   */
  public static function extractLanguageSpecificSolrDynamicFieldDefinition($field_name) {
    $decoded_field_name = Utility::decodeSolrName($field_name);
    if (preg_match('@^[a-z]+' . SolrBackendInterface::SEARCH_API_SOLR_LANGUAGE_SEPARATOR . '[^_]+?_@', $decoded_field_name, $matches)) {
      return Utility::encodeSolrName($matches[0]) . '*';
    }
    return FALSE;
  }

  /**
   * Builds the filter query for a Suggester context given an array of tags.
   *
   * @param array $tags
   *   An array of tags as strings.
   *
   * @return string
   *   The resulting filter query.
   */
  public static function buildSuggesterContextFilterQuery(array $tags) {
    $cfg = [];
    foreach ($tags as $tag) {
      $cfg[] = '+' . self::encodeSolrName($tag);
    }
    return implode(' ', $cfg);
  }

  /**
   * Returns the complete file name for a text file.
   *
   * @param string $text_file_name
   *   The base name of the text file.
   * @param \Drupal\search_api_solr\SolrFieldTypeInterface $solr_field_type
   *   The Solr field type.
   *
   * @return string
   *   The complete file name.
   */
  public static function completeTextFileName(string $text_file_name, SolrFieldTypeInterface $solr_field_type) {
    if ($custom_code = $solr_field_type->getCustomCode()) {
      $text_file_name .= '_' . $custom_code;
    }
    return $text_file_name . '_' . $solr_field_type->getFieldTypeLanguageCode() . '.txt';
  }

  /**
   * Parses the request params.
   *
   * In opposite to parse_str() the same param could occur multiple times.
   *
   * @param \Solarium\Core\Client\Request $request
   *   The Solarium request.
   *
   * @return array
   *   An associative array of parameters.
   */
  public static function parseRequestParams(Request $request) {
    $params = [];
    $parameters = ($request->getMethod() === 'GET') ? explode('&', $request->getQueryString()) : explode('&', $request->getRawData());
    foreach ($parameters as $parameter) {
      if ($parameter) {
        if (strpos($parameter, '=')) {
          list($name, $value) = explode('=', $parameter);
          $params[urldecode($name)][] = urldecode($value);
        }
        else {
          $params[urldecode($parameter)][] = '';
        }
      }
    }
    return $params;
  }

  /**
   * Extracts the cardinality from a dynamic Solr field.
   *
   * @param string $field_name
   *   The dynamic Solr field name.
   *
   * @return string
   *   The cardinality as string 's' or 'm'.
   */
  public static function getSolrFieldCardinality(string $field_name) {
    $parts = explode('_', $field_name);
    return substr($parts[0], -1, 1);
  }

  /**
   * Gets the sortable equivalent of a dynamic Solr field.
   *
   * @param string $field_name
   *   The Search API field name.
   * @param array $solr_field_names
   *   The dynamic Solr field names.
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The Search API query.
   *
   * @return string
   *   The sortable Solr field name.
   *
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   */
  public static function getSortableSolrField(string $field_name, array $solr_field_names, QueryInterface $query) {
    $first_solr_field_name = reset($solr_field_names[$field_name]);

    if (Utility::hasIndexJustSolrDocumentDatasource($query->getIndex())) {
      return $first_solr_field_name;
    }

    // First we need to handle special fields which are prefixed by
    // 'search_api_'. Otherwise they will erroneously be treated as dynamic
    // string fields by the next detection below because they start with an
    // 's'. This way we for example ensure that search_api_relevance isn't
    // modified at all.
    if (strpos($field_name, 'search_api_') === 0) {
      if ('search_api_random' === $field_name) {
        // The default Solr schema provides a virtual field named "random_*"
        // that can be used to randomly sort the results; the field is
        // available only at query-time. See schema.xml for more details about
        // how the "seed" works.
        $params = $query->getOption('search_api_random_sort', []);
        // Random seed: getting the value from parameters or computing a new
        // one.
        $seed = !empty($params['seed']) ? $params['seed'] : mt_rand();
        return $first_solr_field_name . '_' . $seed;
      }
    }
    elseif (strpos($first_solr_field_name, 'spellcheck') === 0 || strpos($first_solr_field_name, 'twm_suggest') === 0) {
      throw new SearchApiSolrException("You can't sort by spellcheck or suggester catalogs.");
    }
    elseif (strpos($first_solr_field_name, 's') === 0 || strpos($first_solr_field_name, 't') === 0) {
      // For string and fulltext fields use the dedicated sort field for faster
      // and language specific sorts. If multiple languages are specified, use
      // the first one.
      $language_ids = $query->getLanguages();
      return Utility::encodeSolrName('sort' . SolrBackendInterface::SEARCH_API_SOLR_LANGUAGE_SEPARATOR . reset($language_ids) . '_' . $field_name);
    }
    elseif (preg_match('/^([a-z]+)m(_.*)/', $first_solr_field_name, $matches)) {
      // For other multi-valued fields (which aren't sortable by nature) we
      // use the same hackish workaround like the DB backend: just copy the
      // first value in a single value field for sorting.
      return $matches[1] . 's' . $matches[2];
    }

    // We could not simply put this into an else condition because that would
    // miss fields like search_api_relevance.
    return $first_solr_field_name;
  }

  /**
   * Normalize the number of rows to fetch to nearest higher power of 2.
   *
   * The _search_all() and _topic_all() streaming expressions need a row limit
   * that matches the real number of documents or higher. To increase the number
   * of query result cache hits we "normalize" the document counts to the
   * nearest higher power of 2. Setting them to a very high fixed value instead
   * makes no sense as this would waste memory in Solr Cloud and might lead to
   * out of memory exceptions. The absolute maximum Solr accepts regardless of
   * the available memory is 2147483629. So we use this a cut-off.
   *
   * @param int $rows
   *
   * @return int
   */
  public static function normalizeMaxRows(int $rows) {
    $i = 2;
    while ($i <= $rows) {
      $i *= 2;
    }
    return ($i > 2147483629) ? 2147483629 : $i;
  }

  /**
   * Flattens keys and fields into a single search string.
   *
   * Formatting the keys into a Solr query can be a bit complex. Keep in mind
   * that the default operator is OR. For some combinations we had to take
   * decisions because different interpretations are possible and we have to
   * ensure that stop words in boolean combinations don't lead to zero results.
   * Therefore this function will produce these queries:
   *
   * @code
   * #conjunction | #negation | fields | parse mode     | return value
   * ---------------------------------------------------------------------------
   * AND          | FALSE     | []     | terms / phrase | +(+A +B)
   * AND          | TRUE      | []     | terms / phrase | -(+A +B)
   * OR           | FALSE     | []     | terms / phrase | +(A B)
   * OR           | TRUE      | []     | terms / phrase | -(A B)
   * AND          | FALSE     | [x]    | terms / phrase | +(x:(+A +B)^1)
   * AND          | TRUE      | [x]    | terms / phrase | -(x:(+A +B)^1)
   * OR           | FALSE     | [x]    | terms / phrase | +(x:(A B)^1)
   * OR           | TRUE      | [x]    | terms / phrase | -(x:(A B)^1)
   * AND          | FALSE     | [x,y]  | terms          | +((+(x:A^1 y:A^1) +(x:B^1 y:B^1)) x:(+A +B)^1 y:(+A +B)^1)
   * AND          | FALSE     | [x,y]  | phrase         | +(x:(+A +B)^1 y:(+A +B)^1)
   * AND          | TRUE      | [x,y]  | terms          | -((+(x:A^1 y:A^1) +(x:B^1 y:B^1)) x:(+A +B)^1 y:(+A +B)^1)
   * AND          | TRUE      | [x,y]  | phrase         | -(x:(+A +B)^1 y:(+A +B)^1)
   * OR           | FALSE     | [x,y]  | terms          | +(((x:A^1 y:A^1) (x:B^1 y:B^1)) x:(A B)^1 y:(A B)^1)
   * OR           | FALSE     | [x,y]  | phrase         | +(x:(A B)^1 y:(A B)^1)
   * OR           | TRUE      | [x,y]  | terms          | -(((x:A^1 y:A^1) (x:B^1 y:B^1)) x:(A B)^1 y:(A B)^1)
   * OR           | TRUE      | [x,y]  | phrase         | -(x:(A B)^1 y:(A B)^1)
   * AND          | FALSE     | [x,y]  | edismax        | +({!edismax qf=x^1,y^1}+A +B)
   * AND          | TRUE      | [x,y]  | edismax        | -({!edismax qf=x^1,y^1}+A +B)
   * OR           | FALSE     | [x,y]  | edismax        | +({!edismax qf=x^1,y^1}A B)
   * OR           | TRUE      | [x,y]  | edismax        | -({!edismax qf=x^1,y^1}A B)
   * AND / OR     | FALSE     | [x]    | direct         | +(x:(A)^1)
   * AND / OR     | TRUE      | [x]    | direct         | -(x:(A)^1)
   * AND / OR     | FALSE     | [x,y]  | direct         | +(x:(A)^1 y:(A)^1)
   * AND / OR     | TRUE      | [x,y]  | direct         | -(x:(A)^1 y:(A)^1)
   * AND          | FALSE     | []     | keys           | +A +B
   * AND          | TRUE      | []     | keys           | -(+A +B)
   * OR           | FALSE     | []     | keys           | A B
   * OR           | TRUE      | []     | keys           | -(A B)
   * @endcode
   *
   * @param array|string $keys
   *   The keys array to flatten, formatted as specified by
   *   \Drupal\search_api\Query\QueryInterface::getKeys() or a phrase string.
   * @param array $fields
   *   (optional) An array of field names.
   * @param string $parse_mode_id
   *   (optional) The parse mode ID. Defaults to "phrase".
   *
   * @return string
   *   A Solr query string representing the same keys.
   *
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   */
  public static function flattenKeys($keys, array $fields = [], string $parse_mode_id = 'phrase'): string {
    switch ($parse_mode_id) {
      case 'keys':
        if (!empty($fields)) {
          throw new SearchApiSolrException(sprintf('Parse mode %s could not handle fields.', $parse_mode_id));
        }
        break;

      case 'edismax':
      case 'direct':
        if (empty($fields)) {
          throw new SearchApiSolrException(sprintf('Parse mode %s requires fields.', $parse_mode_id));
        }
        break;
    }

    $k = [];
    $pre = '+';
    $neg = '';
    $query_parts = [];

    if (is_array($keys)) {
      $queryHelper = \Drupal::service('solarium.query_helper');

      if (isset($keys['#conjunction']) && $keys['#conjunction'] === 'OR') {
        $pre = '';
      }

      if (!empty($keys['#negation'])) {
        $neg = '-';
      }

      $escaped = $keys['#escaped'] ?? FALSE;

      foreach ($keys as $key_nr => $key) {
        // We cannot use \Drupal\Core\Render\Element::children() anymore because
        // $keys is not a valid render array.
        if (!$key || strpos($key_nr, '#') === 0) {
          continue;
        }
        if (is_array($key)) {
          if ('edismax' === $parse_mode_id) {
            throw new SearchApiSolrException('Incompatible parse mode.');
          }
          if ($subkeys = self::flattenKeys($key, $fields, $parse_mode_id)) {
            $query_parts[] = $subkeys;
          }
        }
        elseif ($escaped) {
          $k[] = trim($key);
        }
        else {
          switch ($parse_mode_id) {
            // Using the 'phrase' parse mode, Search API provides one big phrase
            // as keys. Using the 'terms' parse mode, Search API provides chunks
            // of single terms as keys. But these chunks might contain not just
            // real terms but again a phrase if you enter something like this in
            // the search box: term1 "term2 as phrase" term3. This will be
            // converted in this keys array: ['term1', 'term2 as phrase',
            // 'term3']. To have Solr behave like the database backend, these
            // three "terms" should be handled like three phrases.
            case 'terms':
            case 'phrase':
            case 'edismax':
            case 'keys':
              $k[] = $queryHelper->escapePhrase(trim($key));
              break;

            default:
              throw new SearchApiSolrException('Incompatible parse mode.');
          }
        }
      }
    }
    elseif (is_string($keys)) {
      switch ($parse_mode_id) {
        case 'direct':
          $pre = '';
          $k[] = '(' . trim($keys) . ')';
          break;

        default:
          throw new SearchApiSolrException('Incompatible parse mode.');
      }
    }

    if ($k) {
      switch ($parse_mode_id) {
        case 'edismax':
          $query_parts[] = "({!edismax qf='" . implode(' ', $fields) . "'}" . $pre . implode(' ' . $pre, $k) . ')';
          break;

        case 'keys':
          $query_parts[] = $pre . implode(' ' . $pre, $k);
          break;

        case "terms":
          if (count($k) > 1 && count($fields) > 0) {
            $key_parts = [];
            foreach ($k as $l) {
              $field_parts = [];
              foreach ($fields as $f) {
                $field = $f;
                $boost_or_fuzzy = '';
                // Split on operators:
                // - boost (^),
                // - fixed score (^=),
                // - fuzzy/term proximity (~).
                if ($split = preg_split('/([\^~])/', $f, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE)) {
                  $field = array_shift($split);
                  $boost_or_fuzzy = implode('', $split);
                }
                $field_parts[] = $field . ':' . $l . $boost_or_fuzzy;
              }
              $key_parts[] = $pre . '(' . implode(' ', $field_parts) . ')';
            }
            $query_parts[] = '(' . implode(' ', $key_parts) . ')';
          }
        // No break! Execute 'default', too.
        default:
          if (count($fields) > 0) {
            foreach ($fields as $f) {
              $field = $f;
              $boost_or_fuzzy = '';
              // Split on operators for boost (^), fixed score (^=), fuzzy/term proximity (~).
              if ($split = preg_split('/([\^~])/', $f, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE)) {
                $field = array_shift($split);
                $boost_or_fuzzy = implode('', $split);
              }
              $query_parts[] = $field . ':(' . $pre . implode(' ' . $pre, $k) . ')' . $boost_or_fuzzy;
            }
          }
          else {
            $query_parts[] = '(' . $pre . implode(' ' . $pre, $k) . ')';
          }
      }
    }

    if (count($query_parts) === 1) {
      return $neg . reset($query_parts);
    }

    if (count($query_parts) > 1) {
      return $neg . '(' . implode(' ', $query_parts) . ')';
    }

    return '';
  }

  /**
   * Flattens keys into payload_score queries.
   *
   * @param array|string $keys
   *   The keys array to flatten, formatted as specified by
   *   \Drupal\search_api\Query\QueryInterface::getKeys() or a phrase string.
   * @param string $parse_mode_id
   *   (optional) The parse mode ID. Defaults to "phrase".
   *
   * @return string
   *   A Solr query string representing the same keys.
   *
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   */
  public static function flattenKeysToPayloadScore($keys, string $parse_mode_id = 'phrase'): string {
    $k = [];
    $payload_scores = [];

    if (is_array($keys)) {
      $queryHelper = \Drupal::service('solarium.query_helper');

      $escaped = $keys['#escaped'] ?? FALSE;

      foreach ($keys as $key_nr => $key) {
        if (!$key || strpos($key_nr, '#') === 0) {
          continue;
        }
        if (is_array($key)) {
          if ($subkeys = self::flattenKeysToPayloadScore($key, $parse_mode_id)) {
            $payload_scores[] = $subkeys;
          }
        }
        elseif ($escaped) {
          $k[] = trim($key);
        }
        else {
          switch ($parse_mode_id) {
            case 'terms':
            case 'phrase':
            case 'edismax':
              $k[] = $queryHelper->escapePhrase(trim($key));
              break;

            default:
              throw new SearchApiSolrException('Incompatible parse mode.');
          }
        }
      }
    }
    elseif (is_string($keys)) {
      switch ($parse_mode_id) {
        case 'direct':
          // NOP.
          break;

        default:
          throw new SearchApiSolrException('Incompatible parse mode.');
      }
    }

    // See the boost_term_payload field type in schema.xml. If we send shorter
    // or larger keys then defined by solr.LengthFilterFactory we'll trigger a
    // "SpanQuery is null" exception.
    $k = array_filter($k, function ($v) {
      $v = trim($v, '"');
      return (mb_strlen($v) >= 2) && (mb_strlen($v) <= 100);
    });

    if ($k) {
      $payload_scores[] = ' {!payload_score f=boost_term v=' . implode(' func=max} {!payload_score f=boost_term v=', $k) . ' func=max}';
    }

    return implode('', $payload_scores);
  }

  /**
   * Returns whether the index only contains "solr_*" datasources.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The Search API index entity.
   *
   * @return bool
   *   TRUE if the index only contains "solr_*" datasources, FALSE otherwise.
   */
  public static function hasIndexJustSolrDatasources(IndexInterface $index): bool {
    $datasource_ids = $index->getDatasourceIds();
    $datasource_ids = array_filter($datasource_ids, function ($datasource_id) {
      return strpos($datasource_id, 'solr_') !== 0;
    });
    return !$datasource_ids;
  }

  /**
   * Returns whether the index contains any "solr_*" datasources.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The Search API index entity.
   *
   * @return bool
   *   TRUE if the index contains "solr_*" datasources, FALSE otherwise.
   */
  public static function hasIndexSolrDatasources(IndexInterface $index): bool {
    $datasource_ids = $index->getDatasourceIds();
    $datasource_ids = array_filter($datasource_ids, function ($datasource_id) {
      return strpos($datasource_id, 'solr_') === 0;
    });
    return !empty($datasource_ids);
  }

  /**
   * Returns whether the index only contains "solr_document" datasources.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The Search API index entity.
   *
   * @return bool
   *   TRUE if the index only contains "solr_document" datasources, FALSE
   *   otherwise.
   */
  public static function hasIndexJustSolrDocumentDatasource(IndexInterface $index): bool {
    $datasource_ids = $index->getDatasourceIds();
    return (1 == count($datasource_ids)) && in_array('solr_document', $datasource_ids);
  }

  /**
   * Returns the timezone for a query.
   *
   * There's a fallback mechanism to get the time zone:
   * 1. time zone configured for the index
   * 2. the current user's time zone
   * 3. site default time zone
   * 4. storage time zone (UTC)
   *
   * @param \Drupal\search_api\IndexInterface $index
   *
   * @return string
   */
  public static function getTimeZone(IndexInterface $index) {
    $settings = self::getIndexSolrSettings($index);
    $system_date = \Drupal::config('system.date');
    $timezone = '';
    if ($settings['advanced']['timezone']) {
      $timezone = $settings['advanced']['timezone'];
    }
    else {
      if ($system_date->get('timezone.user.configurable')) {
        $timezone = \Drupal::currentUser()->getAccount()->getTimeZone();
      }
    }
    if (!$timezone) {
      $timezone = $system_date->get('timezone.default') ?: date_default_timezone_get();
    }
    return $timezone ?: DateTimeItemInterface::STORAGE_TIMEZONE;
  }

  /**
   * Returns the Solr settings for the given index.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The Search API index entity.
   *
   * @return array
   *   An associative array of settings.
   */
  public static function getIndexSolrSettings(IndexInterface $index) {
    return search_api_solr_merge_default_index_third_party_settings(
      $index->getThirdPartySettings('search_api_solr')
    );
  }

}

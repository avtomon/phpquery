<?php
/**
 * phpQuery is a server-side, chainable, CSS3 selector driven
 * Document Object Model (DOM) API based on jQuery JavaScript Library.
 *
 * @version 0.9.5
 * @link http://code.google.com/p/phpquery/
 * @link http://phpquery-library.blogspot.com/
 * @link http://jquery.com/
 * @author Tobiasz Cudnik <tobiasz.cudnik/gmail.com>
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 * @package phpQuery
 */

namespace PhpQuery;

use PhpQuery\Dom\DomDocumentWrapper as DOMDocumentWrapper;
use PhpQuery\Exceptions\PhpQueryException;

require_once __DIR__ . '/bootstrap.php';

/**
 * Static namespace for phpQuery functions.
 *
 * @author Tobiasz Cudnik <tobiasz.cudnik/gmail.com>
 * @package phpQuery
 */
abstract class PhpQuery
{
    /**
     * XXX: Workaround for mbstring problems
     *
     * @var bool
     */
    public static $mbstringSupport = true;

    /**
     * @var bool
     */
    public static $debug = false;

    /**
     * @var array
     */
    public static $documents = [];

    /**
     * @var null
     */
    public static $defaultDocumentID;

    /**
     * Applies only to HTML.
     *
     * @var string
     */
    public static $defaultDoctype = '<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN"
"http://www.w3.org/TR/html4/loose.dtd">';

    /**
     * @var string
     */
    public static $defaultCharset = 'UTF-8';

    public static $lastModified;

    /**
     * @var int
     */
    public static $active = 0;

    /**
     * @var int
     */
    public static $dumpCount = 0;

    /**
     * @var bool
     */
    public static $enableCssShorthand = false;

    /**
     * Multi-purpose function.
     * Use pq() as shortcut.
     *
     * In below examples, $pq is any result of pq(); function.
     *
     * 1. Import markup into existing document (without any attaching):
     * - Import into selected document:
     *   pq('<div/>')                // DOESNT accept text nodes at beginning of input string !
     * - Import into document with ID from $pq->getDocumentID():
     *   pq('<div/>', $pq->getDocumentID())
     * - Import into same document as \DOMNode belongs to:
     *   pq('<div/>', \DOMNode)
     * - Import into document from phpQuery object:
     *   pq('<div/>', $pq)
     *
     * 2. Run query:
     * - Run query on last selected document:
     *   pq('div.myClass')
     * - Run query on document with ID from $pq->getDocumentID():
     *   pq('div.myClass', $pq->getDocumentID())
     * - Run query on same document as \DOMNode belongs to and use node(s)as root for query:
     *   pq('div.myClass', \DOMNode)
     * - Run query on document from phpQuery object
     *   and use object's stack as root node(s) for query:
     *   pq('div.myClass', $pq)
     *
     * @param string|\DOMNode|\DOMNodeList|array $arg1 HTML markup, CSS Selector, \DOMNode or array of \DOMNodes
     * @param string|PhpQueryObject|\DOMNode $context DOM ID from $pq->getDocumentID(), phpQuery object (determines also query root) or \DOMNode (determines also query root)
     *
     * @return array|\DOMDocument|\DOMNode|PhpQueryObject
     *
     * @throws \Exception
     */
    public static function pq($arg1, $context = null)
    {
        if ($arg1 instanceof \DOMNode && !isset($context)) {
            foreach (phpQuery::$documents as $documentWrapper) {
                $compare = $arg1 instanceof \DOMDocument ? $arg1 : $arg1->ownerDocument;
                if ($documentWrapper->document->isSameNode($compare)) {
                    $context = $documentWrapper->id;
                }
            }
        }

        if (!$context) {
            $domId = self::$defaultDocumentID;
            if (!$domId) {
                throw new PhpQueryException(
                    "Can't use last created DOM, because there isn't any. Use phpQuery::newDocument() first."
                );
            }
        } elseif (is_object($context) && $context instanceof PhpQueryObject) {
            $domId = $context->getDocumentID();
        } elseif ($context instanceof \DOMDocument) {
            $domId = self::getDocumentID($context);
            if (!$domId) {
                $domId = self::newDocument($context)->getDocumentID();
            }
        } elseif ($context instanceof \DOMNode) {
            $domId = self::getDocumentID($context);
            if (!$domId) {
                throw new PhpQueryException('Orphaned DOMNode');
            }
        } else {
            $domId = $context;
        }

        if ($arg1 instanceof PhpQueryObject) {
            if ($arg1->getDocumentID() === $domId) {
                return $arg1;
            }

            $class = get_class($arg1);
            // support inheritance by passing old object to overloaded constructor
            $phpQuery = $class !== 'PhpQuery'
                ? new $class($arg1, $domId)
                : new PhpQueryObject($domId);

            $phpQuery->elements = [];
            foreach ($arg1->elements as $node) {
                $phpQuery->elements[] = $phpQuery->document->importNode($node, true);
            }

            return $phpQuery;
        }

        if ($arg1 instanceof \DOMNode
            || (is_array($arg1) && isset($arg1[0]) && $arg1[0] instanceof \DOMNode)) {
            /*
             * Wrap DOM nodes with phpQuery object, import into document when needed:
             * pq(array($domNode1, $domNode2))
             */
            $phpQuery = new PhpQueryObject($domId);
            if (!($arg1 instanceof \DOMNodeList) && !is_array($arg1)) {
                $arg1 = [$arg1,];
            }

            $phpQuery->elements = [];
            foreach ($arg1 as $node) {
                $sameDocument = $node->ownerDocument instanceof \DOMDocument
                    && !$node->ownerDocument->isSameNode($phpQuery->document);
                $phpQuery->elements[] = $sameDocument ? $phpQuery->document->importNode($node, true)
                    : $node;
            }
            return $phpQuery;
        }

        if (self::isMarkup($arg1)) {
            /**
             * Import HTML:
             * pq('<div/>')
             */
            $phpQuery = new PhpQueryObject($domId);
            return $phpQuery->newInstance($phpQuery->documentWrapper->import($arg1));
        }


        /**
         * Run CSS query:
         * pq('div.myClass')
         */
        $phpQuery = new PhpQueryObject($domId);
        //			if ($context && ($context instanceof PHPQUERY || is_subclass_of($context, 'PhpQueryObject')))
        if ($context && $context instanceof PhpQueryObject) {
            $phpQuery->elements = $context->elements;
        } elseif ($context && $context instanceof \DOMNodeList) {
            $phpQuery->elements = [];
            foreach ($context as $node) {
                $phpQuery->elements[] = $node;
            }
        } elseif ($context && $context instanceof \DOMNode) {
            $phpQuery->elements = [$context,];
        }

        return $phpQuery->find($arg1);
    }

    /**
     * Returns source's document ID.
     *
     * @param \DOMNode|PhpQueryObject|string $source
     *
     * @return string|null
     */
    public static function getDocumentID($source) : ?string
    {
        if ($source instanceof \DOMDocument) {
            foreach (phpQuery::$documents as $id => $document) {
                if ($source->isSameNode($document->document)) {
                    return $id;
                }
            }
        } else if ($source instanceof \DOMNode) {
            foreach (phpQuery::$documents as $id => $document) {
                if ($source->ownerDocument->isSameNode($document->document)) {
                    return $id;
                }
            }

        } elseif ($source instanceof PhpQueryObject) {
            return $source->getDocumentID();
        } elseif (\is_string($source) && isset(phpQuery::$documents[$source])) {
            return $source;
        }

        return null;
    }

    /**
     * Creates new document from markup.
     * Chainable.
     *
     * @param null $markup
     * @param null $contentType
     *
     * @return PhpQueryObject
     * @throws \Exception
     */
    public static function newDocument($markup = null, $contentType = null) : PhpQueryObject
    {
        if (!$markup) {
            $markup = '';
        }

        $documentID = static::createDocumentWrapper($markup, $contentType);

        return new PhpQueryObject($documentID);
    }

    /**
     * @param $html
     * @param null $contentType
     * @param null $documentID
     *
     * @return string|null
     * @throws \Exception
     */
    protected static function createDocumentWrapper($html, $contentType = null, $documentID = null) : ?string
    {
        if (function_exists('domxml_open_mem')) {
            throw new PhpQueryException(
                "Old PHP4 DOM XML extension detected. phpQuery won't work until this extension is enabled."
            );
        }

        $wrapper = null;
        if ($html instanceof \DOMDocument) {
            if (!self::getDocumentID($html)) {
                $wrapper = new DOMDocumentWrapper($html, $contentType, $documentID);
            }
        } else {
            $wrapper = new DOMDocumentWrapper($html, $contentType, $documentID);
        }

        // bind document
        phpQuery::$documents[$wrapper->id] = $wrapper;
        // remember last loaded document
        phpQuery::selectDocument($wrapper->id);

        return $wrapper->id;
    }

    /**
     * Sets default document to $id. Document has to be loaded prior
     * to using this method.
     * $id can be retrieved via getDocumentID() or getDocumentIDRef().
     *
     * @param string $id
     */
    public static function selectDocument($id) : void
    {
        static::$defaultDocumentID = static::getDocumentID($id);
    }

    /**
     * Checks if $input is HTML string, which has to start with '<'.
     *
     * @param String $input
     *
     * @return bool
     */
    public static function isMarkup($input) : bool
    {
        return is_string($input) && strpos(trim($input), '<') === 0;
    }

    /**
     * Returns document with id $id or last used as PhpQueryObject.
     * $id can be retrieved via getDocumentID() or getDocumentIDRef().
     * Chainable.
     *
     * @param null $id
     *
     * @return PhpQueryObject
     *
     * @throws \Exception
     */
    public static function getDocument($id = null) : PhpQueryObject
    {
        if ($id) {
            phpQuery::selectDocument($id);
        } else {
            $id = phpQuery::$defaultDocumentID;
        }

        return new PhpQueryObject($id);
    }

    /**
     * Creates new document from markup.
     * Chainable.
     *
     * @param null $markup
     * @param null $charset
     *
     * @return PhpQueryObject
     *
     * @throws \Exception
     */
    public static function newDocumentHTML($markup = null, $charset = null) : PhpQueryObject
    {
        $contentType = $charset ? ";charset=$charset" : '';
        return self::newDocument($markup, "text/html{$contentType}");
    }

    /**
     * Creates new document from markup.
     * Chainable.
     *
     * @param null $markup
     * @param null $charset
     *
     * @return PhpQueryObject
     * @throws \Exception
     */
    public static function newDocumentXML($markup = null, $charset = null) : PhpQueryObject
    {
        $contentType = $charset ? ";charset=$charset" : '';

        return self::newDocument($markup, "text/xml{$contentType}");
    }

    /**
     * Creates new document from markup.
     * Chainable.
     *
     * @param null $markup
     * @param null $charset
     *
     * @return PhpQueryObject
     * @throws \Exception
     */
    public static function newDocumentXHTML($markup = null, $charset = null) : PhpQueryObject
    {
        $contentType = $charset ? ";charset=$charset" : '';
        return self::newDocument($markup, "application/xhtml+xml{$contentType}");
    }

    /**
     * Creates new document from markup.
     * Chainable.
     *
     * @param $file
     * @param null $charset
     *
     * @return PhpQueryObject
     * @throws \Exception
     */
    public static function newDocumentFileHTML($file, $charset = null) : PhpQueryObject
    {
        $contentType = $charset ? ";charset=$charset" : '';

        return self::newDocumentFile($file, "text/html{$contentType}");
    }

    /**
     * Creates new document from file $file.
     * Chainable.
     *
     * @param $file
     * @param null $contentType
     *
     * @return PhpQueryObject
     * @throws \Exception
     */
    public static function newDocumentFile($file, $contentType = null) : PhpQueryObject
    {
        $documentID = self::createDocumentWrapper(file_get_contents($file), $contentType);

        return new PhpQueryObject($documentID);
    }

    /**
     * Creates new document from markup.
     * Chainable.
     *
     * @param $file
     * @param null $charset
     *
     * @return PhpQueryObject
     *
     * @throws \Exception
     */
    public static function newDocumentFileXML($file, $charset = null) : PhpQueryObject
    {
        $contentType = $charset ? ";charset=$charset" : '';

        return self::newDocumentFile($file, "text/xml{$contentType}");
    }

    /**
     * Creates new document from markup.
     * Chainable.
     *
     * @param $file
     * @param null $charset
     *
     * @return PhpQueryObject
     *
     * @throws \Exception
     */
    public static function newDocumentFileXHTML($file, $charset = null) : PhpQueryObject
    {
        $contentType = $charset ? ";charset=$charset" : '';

        return self::newDocumentFile($file, "application/xhtml+xml{$contentType}");
    }

    /**
     * @param $DOMNodeList
     *
     * @return array
     */
    public static function DOMNodeListToArray($DOMNodeList) : array
    {
        $array = [];
        if (!$DOMNodeList) {
            return $array;
        }

        foreach ($DOMNodeList as $node) {
            $array[] = $node;
        }

        return $array;
    }
}

<?php

namespace PhpQuery;

use PhpQuery\Dom\DomDocumentWrapper;
use PhpQuery\Exceptions\PhpQueryException;
use Sabberworm\CSS\Parser;
use Sabberworm\CSS\Parser as CssParser;
use Sabberworm\CSS\Property\Selector;
use Sabberworm\CSS\Rule\Rule;
use Sabberworm\CSS\RuleSet\DeclarationBlock;

/**
 * Class representing PhpQuery objects.
 *
 * @author Tobiasz Cudnik <tobiasz.cudnik/gmail.com>
 *
 * @package PhpQuery
 */
class PhpQueryObject implements \Iterator, \Countable, \ArrayAccess
{
    public $documentID;
    /**
     * \DOMDocument class.
     *
     * @var \DOMDocument
     */
    public $document;

    public $charset;
    /**
     *
     * @var DomDocumentWrapper
     */
    public $documentWrapper;
    /**
     * XPath interface.
     *
     * @var \DOMXPath
     */
    public $xpath;
    /**
     * Stack of selected elements.
     *
     * @var array|\DOMElement
     */
    public $elements = [];

    /**
     * @var array|\DOMElement
     */
    protected $elementsIterator = [];
    /**
     * Indicated if document is just a fragment (no <html> tag).
     *
     * Every document is realy a full document, so even documentFragments can
     * be queried against <html>, but getDocument(id)->htmlOuter() will return
     * only contents of <body>.
     *
     * @var bool
     */
    public $documentFragment = true;

    /**
     * @access private
     */
    protected $elementsBackup = [];

    /**
     * @access private
     */
    protected $previous;

    /**
     * @access private
     */
    protected $root = [];

    /**
     * Iterator interface helper
     * @access private
     */
    protected $valid = false;

    /**
     * Iterator interface helper
     *
     * @var int
     */
    protected $current;

    /**
     * Indicates whether CSS has been parsed or not. We only parse CSS if needed.
     * @access private
     */
    protected $cssIsParsed = [];

    /**
     * A collection of complete CSS selector strings.
     * @access private;
     */
    protected $cssString = [];

    /**
     * @var array
     */
    protected $attributeCssMapping = [
        'bgcolor' => 'background-color',
        'text'    => 'color',
        'width'   => 'width',
        'height'  => 'height',
    ];

    /**
     * PhpQueryObject constructor.
     *
     * @param $documentID
     *
     * @throws PhpQueryException
     */
    public function __construct($documentID)
    {
        $id = $documentID instanceof self ? $documentID->getDocumentID() : $documentID;

        if (!isset(PhpQuery::$documents[$id])) {
            throw new PhpQueryException("Document with ID '{$id}' isn't loaded. Use PhpQuery::newDocument(\$html) or PhpQuery::newDocumentFile(\$file) first.");
        }

        $this->documentID = $id;
        $this->documentWrapper = &PhpQuery::$documents[$id];
        $this->document = &$this->documentWrapper->document;
        $this->xpath = &$this->documentWrapper->xpath;
        $this->charset = &$this->documentWrapper->charset;
        $this->documentFragment = &$this->documentWrapper->isDocumentFragment;
        $this->root = &$this->documentWrapper->root;
        $this->elements = [$this->root,];
    }

    /**
     * Get object's Document ID.
     *
     * @return string
     */
    public function getDocumentID() : string
    {
        return $this->documentID;
    }

    /**
     *
     * @access private
     *
     * @param $attr
     *
     * @return int|mixed
     */
    public function __get($attr)
    {
        switch ($attr) {
            case 'length':
                return $this->size();

            default:
                return $this->$attr;
        }
    }

    /**
     * Enter description here...
     *
     * @return int
     */
    public function size() : int
    {
        return count($this->elements);
    }

    /**
     * @param null $state
     *
     * @return $this|bool
     */
    public function documentFragment($state = null)
    {
        if ($state) {
            PhpQuery::$documents[$this->getDocumentID()]['documentFragment'] = $state;
            return $this;
        }

        return $this->documentFragment;
    }

    /**
     * Saves object's DocumentID to $var by reference.
     * <code>
     * $myDocumentId;
     * PhpQuery::newDocument('<div/>')
     *     ->getDocumentIDRef($myDocumentId)
     *     ->find('div')->...
     * </code>
     *
     * @param $documentID
     *
     * @return $this
     */
    public function getDocumentIDRef(&$documentID) : self
    {
        $documentID = $this->getDocumentID();

        return $this;
    }

    /**
     * Returns object with stack set to document root.
     *
     * @return self
     *
     * @throws \Exception
     */
    public function getDocument() : self
    {
        return PhpQuery::getDocument($this->getDocumentID());
    }

    /**
     *
     * @return \DOMDocument
     */
    public function getDOMDocument() : \DOMDocument
    {
        return $this->document;
    }

    /**
     * @param null $selector
     *
     * @return self
     *
     * @throws PhpQueryException
     */
    public function not($selector = null) : self
    {
        $stack = [];
        if ($selector instanceof self || $selector instanceof \DOMNode) {
            foreach ($this->stack() as $node) {
                if ($selector instanceof self) {
                    $matchFound = false;
                    /** @var \DOMNode $notNode */
                    foreach ($selector->stack() as $notNode) {
                        if ($notNode->isSameNode($node)) {
                            $matchFound = true;
                        }
                    }
                    if (!$matchFound) {
                        $stack[] = $node;
                    }
                } elseif (!$selector->isSameNode($node)) {
                    $stack[] = $node;
                }
            }
        } else {
            $orgStack = $this->stack();
            $matched = $this->filter($selector, true)->stack();
            foreach ($orgStack as $node) {
                if (!$this->elementsContainsNode($node, $matched)) {
                    $stack[] = $node;
                }
            }
        }

        return $this->newInstance($stack);
    }

    /**
     * Internal stack iterator.
     *
     * @param null $nodeTypes
     *
     * @return array
     */
    public function stack($nodeTypes = null) : array
    {
        if (!isset($nodeTypes)) {
            return $this->elements;
        }

        if (!\is_array($nodeTypes)) {
            $nodeTypes = [$nodeTypes,];
        }

        $return = [];
        foreach ($this->elements as $node) {
            if (\in_array($node->nodeType, $nodeTypes, true)) {
                $return[] = $node;
            }
        }

        return $return;
    }

    /**
     * @param $selector
     * @param null $nodes
     *
     * @return bool
     *
     * @throws PhpQueryException
     */
    public function is($selector, $nodes = null) : bool
    {
        if (!$selector) {
            return false;
        }

        $oldStack = $this->elements;
        if ($nodes && is_array($nodes)) {
            $this->elements = $nodes;
        } elseif ($nodes) {
            $this->elements = [$nodes,];
        }

        $this->filter($selector, true);
        $stack = $this->elements;
        $this->elements = $oldStack;
        if ($nodes) {
            return (bool)$stack ?: false;
        }

        return (bool)count($stack);
    }

    /**
     * @link http://docs.jquery.com/Traversing/filter
     *
     * @param $selectors
     * @param bool $_skipHistory
     *
     * @return self
     *
     * @throws PhpQueryException
     */
    public function filter($selectors, $_skipHistory = false) : self
    {
        if (!$_skipHistory) {
            $this->elementsBackup = $this->elements;
        }

        $notSimpleSelector = [' ', '>', '~', '+', '/',];
        if (!is_array($selectors)) {
            $selectors = $this->parseSelector($selectors);
        }

        $finalStack = [];
        foreach ($selectors as $selector) {
            $stack = [];
            if (!$selector) {
                break;
            }

            // avoid first space or /
            if (\in_array($selector[0], $notSimpleSelector, true)) {
                $selector = array_slice($selector, 1);
            }
            // PER NODE selector chunks
            foreach ($this->stack() as $node) {
                $break = false;
                foreach ($selector as $s) {
                    if (!($node instanceof \DOMElement)) {
                        // all besides \DOMElement
                        if ($s[0] === '[') {
                            $attr = trim($s, '[]');
                            if (mb_strpos($attr, '=')) {
                                [$attr, $val] = explode('=', $attr);
                                if ($attr === 'nodeType' && $node->nodeType !== $val) {
                                    $break = true;
                                }
                            }
                        } else {
                            $break = true;
                        }
                    } elseif ($s[0] === '#') {
                        if ($node->getAttribute('id') !== substr($s, 1)) {
                            $break = true;
                        }
                        // CLASSES
                    } else if ($s[0] === '.') {
                        if (!$this->matchClasses($s, $node)) {
                            $break = true;
                        }
                    } else if ($s[0] === '[') {
                        // strip side brackets
                        $attr = trim($s, '[]');
                        if (mb_strpos($attr, '=')) {
                            [$attr, $val] = explode('=', $attr);
                            $val = self::unQuote($val);
                            if ($attr === 'nodeType') {
                                if ($val !== $node->nodeType) {
                                    $break = true;
                                }
                            } else if ($this->isRegexp($attr)) {
                                $val = extension_loaded('mbstring')
                                && PhpQuery::$mbstringSupport ? quotemeta(trim($val, '"\''))
                                    : preg_quote(trim($val, '"\''), '@');
                                // switch last character
                                switch (substr($attr, -1)) {
                                    // quotemeta used insted of preg_quote
                                    // http://code.google.com/p/phpquery/issues/detail?id=76
                                    case '^':
                                        $pattern = '^' . $val;
                                        break;
                                    case '*':
                                        $pattern = '.*' . $val . '.*';
                                        break;
                                    case '$':
                                        $pattern = '.*' . $val . '$';
                                        break;
                                }
                                // cut last character
                                $attr = substr($attr, 0, -1);
                                $isMatch = null;
                                if (isset($pattern)) {
                                    $isMatch = extension_loaded('mbstring') && PhpQuery::$mbstringSupport
                                        ? mb_ereg_match($pattern, $node->getAttribute($attr))
                                        : preg_match("@{$pattern}@", $node->getAttribute($attr));
                                }
                                if (!$isMatch) {
                                    $break = true;
                                }
                            } elseif ($node->getAttribute($attr) !== $val) {
                                $break = true;
                            }
                        } elseif (!$node->hasAttribute($attr)) {
                            $break = true;
                            // PSEUDO CLASSES
                        }
                    } else if (trim($s)) {
                        if ($s !== '*') {
                            if (isset($node->tagName)) {
                                if ($node->tagName !== $s) {
                                    $break = true;
                                }
                            } else if ($s === 'html' && !$this->isRoot($node)) {
                                $break = true;
                            }
                        }
                        // AVOID NON-SIMPLE SELECTORS
                    } else if (\in_array($s, $notSimpleSelector, true)) {
                        $break = true;
                    }

                    if ($break) {
                        break;
                    }
                }
                // if element passed all chunks of selector - add it to new stack
                if (!$break) {
                    $stack[] = $node;
                }
            }
            $tmpStack = $this->elements;
            $this->elements = $stack;
            // PER ALL NODES selector chunks
            foreach ($selector as $s) {
                // PSEUDO CLASSES
                if (strpos($s, ':') === 0) {
                    $this->pseudoClasses($s);
                }
            }

            foreach ($this->elements as $node) {
                // XXX it should be merged without duplicates
                // but jQuery doesnt do that
                $finalStack[] = $node;
            }

            $this->elements = $tmpStack;
        }

        $this->elements = $finalStack;
        if ($_skipHistory) {
            return $this;
        }

        return $this->newInstance();
    }

    /**
     * Returns new instance of actual class.
     *
     * @param null|mixed $newStack - Will replace old stack with new and move old one to history.c
     *
     * @return self
     *
     * @throws PhpQueryException
     * @throws \Exception
     */
    public function newInstance($newStack = null) : self
    {
        $class = get_class($this);
        // support inheritance by passing old object to overloaded constructor
        $new = $class !== 'PhpQuery'
            ? new $class($this, $this->getDocumentID())
            : new static($this->getDocumentID());

        $new->previous = $this;
        if (null === $newStack) {
            $new->elements = $this->elements;
            if ($this->elementsBackup) {
                $this->elements = $this->elementsBackup;
            }
        } else if (is_string($newStack)) {
            $new->elements = PhpQuery::pq($newStack, $this->getDocumentID())->stack();
        } else {
            $new->elements = $newStack;
        }

        return $new;
    }

    /**
     * @param $query
     *
     * @return array
     */
    protected function parseSelector($query) : array
    {
        // clean spaces
        $query = trim(preg_replace('@\s+@', ' ', preg_replace('@\s*(>|\\+|~)\s*@', '\\1', (string)$query)));
        $queries = [[],];
        if (!$query) {
            return $queries;
        }

        $return = &$queries[0];
        $specialChars = ['>', ' ',];
        $specialCharsMapping = [];
        $strlen = mb_strlen($query);
        $classChars = ['.', '-',];
        $pseudoChars = ['-',];
        $tagChars = ['*', '|', '-',];
        // split multibyte string
        // http://code.google.com/p/phpquery/issues/detail?id=76
        $_query = [];
        for ($i = 0; $i < $strlen; $i++) {
            $_query[] = mb_substr($query, $i, 1);
        }

        $query = $_query;
        // it works, but i dont like it...
        $i = 0;
        while ($i < $strlen) {
            $c = $query[$i];
            $tmp = '';
            // TAG
            if ($this->isChar($c) || \in_array($c, $tagChars, true)) {
                while (isset($query[$i])
                    && ($this->isChar($query[$i]) || \in_array($query[$i], $tagChars, true))) {

                    $tmp .= $query[$i];
                    $i++;
                }
                $return[] = $tmp;
                // IDs
            } else if ($c === '#') {
                $i++;
                while (isset($query[$i]) && ($this->isChar($query[$i]) || $query[$i] === '-')) {
                    $tmp .= $query[$i];
                    $i++;
                }

                $return[] = '#' . $tmp;
                // SPECIAL CHARS
            } else if (\in_array($c, $specialChars, true)) {
                $return[] = $c;
                $i++;
            } else if (isset($specialCharsMapping[$c])) {
                $return[] = $specialCharsMapping[$c];
                $i++;
                // COMMA
            } else if ($c === ',') {
                $queries[] = [];
                $return = &$queries[count($queries) - 1];
                $i++;
                while (isset($query[$i]) && $query[$i] === ' ') {
                    $i++;
                }
                // CLASSES
            } else if ($c === '.') {
                while (isset($query[$i]) && ($this->isChar($query[$i]) || \in_array($query[$i], $classChars, true))) {
                    $tmp .= $query[$i];
                    $i++;
                }

                $return[] = $tmp;
                // ~ General Sibling Selector
            } else if ($c === '~') {
                $spaceAllowed = true;
                $tmp .= $query[$i++];
                while (isset($query[$i])
                    && ($this->isChar($query[$i]) || \in_array($query[$i], $classChars, true)
                        || $query[$i] === '*' || ($query[$i] === ' ' && $spaceAllowed))) {

                    if ($query[$i] !== ' ') {
                        $spaceAllowed = false;
                    }

                    $tmp .= $query[$i];
                    $i++;
                }

                $return[] = $tmp;
                // + Adjacent sibling selectors
            } else if ($c === '+') {
                $spaceAllowed = true;
                $tmp .= $query[$i++];
                while (isset($query[$i])
                    && ($this->isChar($query[$i]) || \in_array($query[$i], $classChars, true)
                        || $query[$i] === '*' || ($spaceAllowed && $query[$i] === ' '))) {

                    if ($query[$i] !== ' ') {
                        $spaceAllowed = false;
                    }

                    $tmp .= $query[$i];
                    $i++;
                }

                $return[] = $tmp;
                // ATTRS
            } else if ($c === '[') {
                $stack = 1;
                $tmp .= $c;
                while (isset($query[++$i])) {
                    $tmp .= $query[$i];
                    if ($query[$i] === '[') {
                        $stack++;
                    } else if ($query[$i] === ']') {
                        $stack--;
                        if (!$stack) {
                            break;
                        }
                    }
                }

                $return[] = $tmp;
                $i++;
                // PSEUDO CLASSES
            } else if ($c === ':') {
                $tmp .= $query[$i++];
                while (isset($query[$i])
                    && ($this->isChar($query[$i]) || \in_array($query[$i], $pseudoChars, true))) {

                    $tmp .= $query[$i];
                    $i++;
                }
                // with arguments ?
                if (isset($query[$i]) && $query[$i] === '(') {
                    $tmp .= $query[$i];
                    $stack = 1;
                    while (isset($query[++$i])) {
                        $tmp .= $query[$i];
                        if ($query[$i] === '(') {
                            $stack++;
                        } else if ($query[$i] === ')') {
                            $stack--;
                            if (!$stack) {
                                break;
                            }
                        }
                    }

                    $return[] = $tmp;
                    $i++;
                } else {
                    $return[] = $tmp;
                }
            } else {
                $i++;
            }
        }
        foreach ($queries as $k => $q) {
            if (isset($q[0])) {
                if (isset($q[0][0]) && $q[0][0] === ':') {
                    array_unshift($queries[$k], '*');
                }

                if ($q[0] !== '>') {
                    array_unshift($queries[$k], ' ');
                }
            }
        }

        return $queries;
    }

    /**
     * Determines if $char is really a char.
     *
     * @param string $char
     *
     * @return bool
     *
     * @access private
     */
    protected function isChar($char) : bool
    {
        return extension_loaded('mbstring') && PhpQuery::$mbstringSupport
            ? mb_eregi('\w', $char)
            : preg_match('@\w@', $char);
    }

    /**
     * In the future, when PHP will support XLS 2.0, then we would do that this way:
     * contains(tokenize(@class, '\s'), "something")
     *
     * @param $class
     * @param $node
     *
     * @return bool
     */
    protected function matchClasses($class, \DOMElement $node) : bool
    {
        // multi-class
        if (mb_strpos($class, '.', 1)) {
            $classes = explode('.', substr($class, 1));
            $classesCount = count($classes);
            $nodeClasses = explode(' ', $node->getAttribute('class'));
            $nodeClassesCount = count($nodeClasses);
            if ($classesCount > $nodeClassesCount) {
                return false;
            }

            $diff = \count(array_diff($classes, $nodeClasses));
            if (!$diff) {
                return true;
            }

            return false;
            // single-class
        }

        $substr = substr($class, 1);
        return $substr ? \in_array($substr, explode(' ', $node->getAttribute('class')), true) : false;
    }

    /**
     * @param $value
     *
     * @return bool|string
     */
    protected static function unQuote($value)
    {
        return $value[0] === '\'' || $value[0] === '"' ? substr($value, 1, -1) : $value;
    }

    /**
     * @param string $pattern
     *
     * @return bool
     */
    protected function isRegexp(string $pattern) : bool
    {
        return in_array($pattern[mb_strlen($pattern) - 1], [
            '^',
            '*',
            '$',
        ]);
    }

    /**
     * @param $class
     *
     * @throws PhpQueryException
     */
    protected function pseudoClasses($class) : void
    {
        $class = ltrim($class, ':');
        $haveArgs = mb_strpos($class, '(');
        $args = '';
        if ($haveArgs !== false) {
            $args = substr($class, $haveArgs + 1, -1);
            $class = substr($class, 0, $haveArgs);
        }

        switch ($class) {
            case 'even':
            case 'odd':
                $stack = [];
                foreach ($this->elements as $i => $node) {
                    if ($class === 'even' && ($i % 2) === 0) {
                        $stack[] = $node;
                    } elseif ($class === 'odd' && $i % 2) {
                        $stack[] = $node;
                    }
                }

                $this->elements = $stack;
                break;

            case 'eq':
                $k = (int)$args;
                $this->elements = isset($this->elements[$k])
                    ? [$this->elements[$k],]
                    : [];
                break;

            case 'gt':
                $this->elements = array_slice($this->elements, $args + 1);
                break;

            case 'lt':
                $this->elements = array_slice($this->elements, 0, $args + 1);
                break;

            case 'first':
                if (isset($this->elements[0])) {
                    $this->elements = [$this->elements[0],];
                }
                break;

            case 'last':
                if ($this->elements) {
                    $this->elements = [$this->elements[count($this->elements) - 1],];
                }
                break;

            case 'contains':
                $text = trim($args, "\"'");
                $stack = [];
                foreach ($this->elements as $node) {
                    if (mb_stripos($node->textContent, $text) === false) {
                        continue;
                    }

                    $stack[] = $node;
                }

                $this->elements = $stack;
                break;

            case 'not':
                $selector = self::unQuote($args);
                $this->elements = $this->not($selector)->stack();
                break;

            case 'slice':
                $args = explode(',', str_replace(', ', ',', trim($args, "\"'")));
                $start = $args[0];
                $end = $args[1] ?? null;
                if ($end > 0) {
                    $end -= $start;
                }

                $this->elements = array_slice($this->elements, $start, $end);
                break;

            case 'has':
                $selector = trim($args, "\"'");
                $stack = [];
                foreach ($this->stack(1) as $el) {
                    if ($this->find($selector, $el, true)->size()) {
                        $stack[] = $el;
                    }
                }

                $this->elements = $stack;
                break;

            case 'submit':
            case 'reset':
                $this->elements = $this->filter("input[type=$class], button[type=$class]")->stack();
                break;

            case 'input':
                $this->elements = $this->filter('input, textarea, select, button')->stack();
                break;

            case 'password':
            case 'checkbox':
            case 'radio':
            case 'hidden':
            case 'image':
            case 'file':
                $this->elements = $this->filter("input[type=$class]")->stack();
                break;

            case 'parent':
                $this->elements = $this->parent()->stack();
                break;

            case 'disabled':
            case 'selected':
            case 'checked':
                $this->elements = $this->filter("[$class]")->stack();
                break;

            case 'enabled':
                $this->elements = $this->not(':disabled')->stack();
                break;

            case 'header':
                $this->elements = $this->filter('h1, h2, h3, h4, h5, h6, h7')->stack();
                break;

            case 'only-child':
                $this->elements = array_filter($this->elements, static function ($node) {
                    return pq($node)->siblings()->size() === 0;
                });
                break;

            case 'first-child':
                $this->elements = array_filter($this->elements, static function ($node) {
                    return pq($node)->prevAll()->size() === 0;
                });
                break;

            case 'last-child':
                $this->elements = array_filter($this->elements, static function ($node) {
                    return pq($node)->nextAll()->size() === 0;
                });
                break;

            default:
                throw new PhpQueryException("Unknown pseudoclass '$class'.");
        }
    }

    /**
     * @param $selectors
     * @param null $context
     * @param bool $noHistory
     *
     * @return self
     *
     * @throws PhpQueryException
     */
    public function find($selectors, $context = null, $noHistory = false) : self
    {
        if (!$noHistory) {
            // backup last stack /for end()/
            $this->elementsBackup = $this->elements;
            // allow to define context
        }

        if (isset($context)) {
            if (!is_array($context) && $context instanceof \DOMElement) {
                $this->elements = [$context,];
            } elseif (is_array($context)) {
                $this->elements = [];
                foreach ($context as $c) {
                    if ($c instanceof \DOMElement) {
                        $this->elements[] = $c;
                    }
                }
            } elseif ($context instanceof self) {
                $this->elements = $context->elements;
            }
        }

        $queries = $this->parseSelector($selectors);
        $XQuery = '';
        // remember stack state because of multi-queries
        $oldStack = $this->elements;
        // here we will be keeping found elements
        $stack = [];
        foreach ($queries as $selector) {
            $this->elements = $oldStack;
            $delimiterBefore = false;
            foreach ($selector as $s) {
                // TAG
                $isTag = extension_loaded('mbstring') && PhpQuery::$mbstringSupport ? mb_ereg_match('^[\w|\||-]+$', $s)
                    || $s === '*' : preg_match('@^[\w|\||-]+$@', $s) || $s === '*';
                if ($isTag) {
                    if ($this->isXML()) {
                        // namespace support
                        if (mb_strpos($s, '|') !== false) {
                            $tag = null;
                            [$ns, $tag] = explode('|', $s);
                            $XQuery .= "$ns:$tag";
                        } else if ($s === '*') {
                            $XQuery .= '*';
                        } else {
                            $XQuery .= "*[local-name()='$s']";
                        }
                    } else {
                        $XQuery .= $s;
                    }
                    // ID
                } else if ($s[0] === '#') {
                    if ($delimiterBefore) {
                        $XQuery .= '*';
                    }

                    $XQuery .= "[@id='" . substr($s, 1) . "']";
                    // ATTRIBUTES
                } else if ($s[0] === '[') {
                    if ($delimiterBefore) {
                        $XQuery .= '*';
                    }
                    // strip side brackets
                    $attr = trim($s, '][');
                    $execute = false;
                    // attr with specifed value
                    if (mb_strpos($s, '=')) {
                        $value = null;
                        [$attr, $value] = explode('=', $attr);
                        $value = trim($value, "'\"");
                        if ($this->isRegexp($attr)) {
                            // cut regexp character
                            $attr = substr($attr, 0, -1);
                            $execute = true;
                            $XQuery .= "[@{$attr}]";
                        } else {
                            $XQuery .= "[@{$attr}='{$value}']";
                        }
                        // attr without specified value
                    } else {
                        $XQuery .= "[@{$attr}]";
                    }
                    if ($execute) {
                        $this->runQuery($XQuery, $s, 'is');
                        $XQuery = '';
                        if (!$this->size()) {
                            break;
                        }
                    }
                    // CLASSES
                } else if ($s[0] === '.') {
                    // thx wizDom ;)
                    if ($delimiterBefore) {
                        $XQuery .= '*';
                    }
                    $XQuery .= '[@class]';
                    $this->runQuery($XQuery, $s, 'matchClasses');
                    $XQuery = '';
                    if (!$this->size()) {
                        break;
                    }
                    // ~ General Sibling Selector
                } else if ($s[0] === '~') {
                    $this->runQuery($XQuery);
                    $XQuery = '';
                    $this->elements = $this->siblings(substr($s, 1))->elements;
                    if (!$this->size()) {
                        break;
                    }
                    // + Adjacent sibling selectors
                } else if ($s[0] === '+') {
                    $this->runQuery($XQuery);
                    $XQuery = '';
                    $subSelector = substr($s, 1);
                    $subElements = $this->elements;
                    $this->elements = [];
                    foreach ($subElements as $node) {
                        // search first \DOMElement sibling
                        $test = $node->nextSibling;
                        while ($test && !($test instanceof \DOMElement)) {
                            $test = $test->nextSibling;
                        }

                        if ($test && $this->is($subSelector, $test)) {
                            $this->elements[] = $test;
                        }
                    }
                    if (!$this->size()) {
                        break;
                    }
                    // PSEUDO CLASSES
                } else if ($s[0] === ':') {
                    if ($XQuery) {
                        $this->runQuery($XQuery);
                        $XQuery = '';
                    }

                    if (!$this->size()) {
                        break;
                    }

                    $this->pseudoClasses($s);
                    if (!$this->size()) {
                        break;
                    }
                    // DIRECT DESCENDANDS
                } else if ($s === '>') {
                    $XQuery .= '/';
                    $delimiterBefore = 2;
                    // ALL DESCENDANDS
                } else if ($s === ' ') {
                    $XQuery .= '//';
                    $delimiterBefore = 2;
                    // ERRORS
                }

                $delimiterBefore = $delimiterBefore === 2;
            }
            // run query if any
            if ($XQuery && $XQuery !== '//') {
                $this->runQuery($XQuery);
                $XQuery = '';
            }

            foreach ($this->elements as $node) {
                if (!$this->elementsContainsNode($node, $stack)) {
                    $stack[] = $node;
                }
            }
        }

        $this->elements = $stack;

        return $this->newInstance();
    }

    /**
     * @return bool
     */
    public function isXML() : bool
    {
        return $this->documentWrapper->isXML;
    }

    /**
     * @param $XQuery
     * @param null $selector
     * @param null $compare
     *
     * @return bool
     */
    protected function runQuery($XQuery, $selector = null, $compare = null) : bool
    {
        if ($compare && !method_exists($this, $compare)) {
            return false;
        }

        $stack = [];
        foreach ($this->stack([1, 9, 13,]) as $k => $stackNode) {
            $detachAfter = false;
            // to work on detached nodes we need temporary place them somewhere
            // thats because context xpath queries sucks ;]
            $testNode = $stackNode;
            while ($testNode) {
                if (!$testNode->parentNode && !$this->isRoot($testNode)) {
                    $this->root->appendChild($testNode);
                    $detachAfter = $testNode;
                    break;
                }

                $testNode = $testNode->parentNode ?? null;
            }

            $xpath = $this->getNodeXpath($stackNode);
            $query = $XQuery === '//' && $xpath === '/html[1]' ? '//*' : $xpath . $XQuery;
            // run query, get elements
            $nodes = $this->xpath->query($query);
            foreach ($nodes as $node) {
                $matched = false;
                if ($compare) {
                    if ($this->$compare($selector, $node)) {
                        $matched = true;
                    }
                } else {
                    $matched = true;
                }

                if ($matched) {
                    $stack[] = $node;
                }
            }

            if ($detachAfter) {
                $this->root->removeChild($detachAfter);
            }
        }

        $this->elements = $stack;

        return true;
    }

    /**
     * @param null $oneNode
     *
     * @return array|mixed
     */
    protected function getNodeXpath($oneNode = null)
    {
        $return = [];
        $loop = $oneNode ? [$oneNode,] : $this->elements;
        foreach ($loop as $node) {
            if ($node instanceof \DOMDocument) {
                $return[] = '';
                continue;
            }
            $xpath = [];
            while (!($node instanceof \DOMDocument)) {
                $i = 1;
                $sibling = $node;
                while ($sibling->previousSibling) {
                    $sibling = $sibling->previousSibling;
                    $isElement = $sibling instanceof \DOMElement;
                    if ($isElement && $sibling->tagName === $node->tagName) {
                        $i++;
                    }
                }
                $xpath[] = $this->isXML() ? "*[local-name()='{$node->tagName}'][{$i}]"
                    : "{$node->tagName}[{$i}]";
                $node = $node->parentNode;
            }
            $xpath = implode('/', array_reverse($xpath));
            $return[] = '/' . $xpath;
        }

        return $oneNode ? $return[0] : $return;
    }

    /**
     * @param null $selector
     *
     * @return self
     *
     * @throws PhpQueryException
     */
    public function siblings($selector = null) : self
    {
        $stack = [];
        $siblings = array_merge(
            $this->getElementSiblings('previousSibling', $selector),
            $this->getElementSiblings('nextSibling', $selector)
        );
        foreach ($siblings as $node) {
            if (!$this->elementsContainsNode($node, $stack)) {
                $stack[] = $node;
            }
        }

        return $this->newInstance($stack);
    }

    /**
     * @param $direction
     * @param null $selector
     * @param bool $limitToOne
     *
     * @return array
     *
     * @throws PhpQueryException
     */
    protected function getElementSiblings($direction, $selector = null, $limitToOne = false) : array
    {
        $stack = [];
        foreach ($this->stack() as $node) {
            $test = $node;
            while (isset($test->{$direction}) && $test->{$direction}) {
                $test = $test->{$direction};
                if (!$test instanceof \DOMElement) {
                    continue;
                }

                $stack[] = $test;
                if ($limitToOne) {
                    break;
                }
            }
        }

        if ($selector) {
            $stackOld = $this->elements;
            $this->elements = $stack;
            $stack = $this->filter($selector, true)->stack();
            $this->elements = $stackOld;
        }

        return $stack;
    }

    /**
     * @param $nodeToCheck
     * @param null $elementsStack
     *
     * @return bool
     */
    protected function elementsContainsNode($nodeToCheck, $elementsStack = null) : bool
    {
        $loop = $elementsStack ?? $this->elements;
        foreach ($loop as $node) {
            if ($node->isSameNode($nodeToCheck)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param null $attr
     * @param null $value
     *
     * @return array|self|string|null
     */
    public function attr($attr = null, $value = null)
    {
        foreach ($this->stack(1) as $node) {
            if (null !== $value) {
                $loop = $attr === '*' ? $this->getNodeAttrs($node) : [$attr,];
                foreach ($loop as $a) {
                    // while document's charset is also not UTF-8
                    @$node->setAttribute($a, $value);
                }
            } else if ($attr === '*') {
                // jQuery difference
                $return = [];
                foreach ($node->attributes as $n => $v) {
                    $return[$n] = $v->value;
                }

                return $return;
            } else {
                return $node->hasAttribute($attr) ? $node->getAttribute($attr) : null;
            }
        }

        return null === $value ? '' : $this;
    }

    /**
     * @param $node
     *
     * @return array
     */
    protected function getNodeAttrs($node) : array
    {
        $return = [];
        foreach ($node->attributes as $n => $o) {
            $return[] = $n;
        }

        return $return;
    }

    /**
     * @return bool
     */
    public function isXHTML() : bool
    {
        return $this->documentWrapper->isXHTML;
    }

    /**
     * @return bool
     */
    public function isHTML() : bool
    {
        return $this->documentWrapper->isHTML;
    }

    /**
     * @param null $val
     *
     * @return self|array|string|null
     *
     * @throws PhpQueryException
     * @throws \Exception
     */
    public function val($val = null)
    {
        if (!isset($val)) {
            if ($this->eq(0)->is('select')) {
                $selected = $this->eq(0)->find('option[selected=selected]');
                if ($selected->is('[value]')) {
                    return $selected->attr('value');
                }

                return $selected->text();
            }

            if ($this->eq(0)->is('textarea')) {
                return $this->eq(0)->markup();
            }

            return $this->eq(0)->attr('value');
        }

        $_val = null;
        foreach ($this->stack(1) as $node) {
            $node = pq($node, $this->getDocumentID());
            if (\is_array($val)
                && \in_array($node->attr('type'), ['checkbox', 'radio',])) {

                $isChecked = \in_array($node->attr('value'), $val, true) || \in_array($node->attr('name'), $val, true);
                if ($isChecked) {
                    $node->attr('checked', 'checked');
                } else {
                    $node->removeAttr('checked');
                }
            } elseif ($node->get(0)->tagName === 'select') {
                if (!isset($_val)) {
                    $_val = [];
                    if (!is_array($val)) {
                        $_val = [(string)$val,];
                    } else {
                        foreach ($val as $v) {
                            $_val[] = $v;
                        }
                    }
                }

                /** @var self $options */
                $options = $node['option'];
                foreach ($options->stack(1) as $option) {
                    $option = pq($option, $this->getDocumentID());
                    // XXX: workaround for string comparsion, see issue #96
                    // http://code.google.com/p/phpquery/issues/detail?id=96
                    $selected = (null === $option->attr('value'))
                        ? \in_array($option->markup(), $_val, true)
                        : \in_array($option->attr('value'), $_val, true);

                    if ($selected) {
                        $option->attr('selected', 'selected');
                    } else {
                        $option->removeAttr('selected');
                    }
                }
            } elseif ($node->get(0)->tagName === 'textarea') {
                $node->markup($val);
            } else {
                $node->attr('value', $val);
            }
        }

        return $this;
    }

    /**
     * @param $num
     *
     * @return self
     *
     * @throws PhpQueryException
     */
    public function eq($num) : self
    {
        $oldStack = $this->elements;
        $this->elementsBackup = $this->elements;
        $this->elements = [];
        if (isset($oldStack[$num])) {
            $this->elements[] = $oldStack[$num];
        }

        return $this->newInstance();
    }

    /**
     * @param null $text
     *
     * @return bool|self|string
     *
     * @throws PhpQueryException
     */
    public function text($text = null)
    {
        if (isset($text)) {
            return $this->html(htmlspecialchars($text));
        }

        $return = '';
        foreach ($this->elements as $node) {
            $text = $node->textContent;
            if ($text && \count($this->elements) > 1) {
                $text .= "\n";
            }

            $return .= $text;
        }

        return $return;
    }

    /**
     * @param null $html
     *
     * @return self|bool|string
     *
     * @throws PhpQueryException
     * @throws \Exception
     */
    public function html($html = null)
    {
        if (isset($html)) {
            // INSERT
            $nodes = $this->documentWrapper->import($html);
            $this->empty();
            foreach ($this->stack(1) as $alreadyAdded => $node) {
                // for now, limit events for textarea
                foreach ($nodes as $newNode) {
                    $node->appendChild($alreadyAdded ? $newNode->cloneNode(true) : $newNode);
                }
            }

            return $this;
        }

        return $this->documentWrapper->markup($this->elements, true);
    }

    /**
     * @param null $markup
     *
     * @return bool|mixed|PhpQueryObject|string
     * @throws PhpQueryException
     */
    public function markup($markup = null)
    {
        if ($this->documentWrapper->isXML) {
            return $this->xml($markup);
        }

        return $this->html($markup);
    }

    /**
     * @param $attr
     *
     * @return self
     */
    public function removeAttr($attr) : self
    {
        foreach ($this->stack(1) as $node) {
            $loop = $attr === '*' ? $this->getNodeAttrs($node) : [$attr,];
            foreach ($loop as $a) {
                $node->removeAttribute($a);
            }
        }

        return $this;
    }

    /**
     * Return matched DOM nodes.
     *
     * @param int $index
     *
     * @return array|\DOMElement Single \DOMElement or array of \DOMElement.
     */
    public function get($index = null)
    {
        return isset($index) ? ($this->elements[$index] ?? null) : $this->elements;
    }

    /**
     * @throws PhpQueryException
     * @throws \Exception
     */
    public function parseCSS()
    {
        foreach (PhpQuery::pq('style', $this->getDocumentID()) as $style) {
            $this->cssString[$this->getDocumentID()] .= PhpQuery::pq($style)->text();
        }

        $CssParser = new CssParser($this->cssString[$this->getDocumentID()]);
        $CssDocument = $CssParser->parse();
        /** @var DeclarationBlock $ruleset */
        foreach ($CssDocument->getAllRuleSets() as $ruleset) {
            /** @var Selector $selector */
            foreach ($ruleset->getSelectors() as $selector) {
                $specificity = $selector->getSpecificity();
                foreach (PhpQuery::pq($selector->getSelector(), $this->getDocumentID()) as $el) {
                    $existing = pq($el)->data('phpquery-css');
                    if (PhpQuery::$enableCssShorthand) {
                        $ruleset->expandShorthands();
                    }

                    /** @var Rule $value */
                    foreach ($ruleset->getRules() as $value) {
                        $rule = $value->getRule();
                        if (!isset($existing[$rule])
                            || $existing[$rule]['specificity'] <= $specificity) {
                            $value = $value->getValue();
                            $value = is_object($value) ? (string)$value : $value;
                            $existing[$rule] = [
                                'specificity' => $specificity,
                                'value'       => $value,
                            ];
                        }
                    }

                    PhpQuery::pq($el)->data('phpquery-css', $existing);
                    $this->bubbleCSS(PhpQuery::pq($el));
                }
            }
        }

        foreach (PhpQuery::pq('*', $this->getDocumentID()) as $el) {
            $existing = pq($el)->data('phpquery-css');
            $style = pq($el)->attr('style');
            $style = $style !== '' ? explode(';', $style) : [];
            foreach ($this->attributeCssMapping as $map => $cssEquivalent) {
                if ($el->hasAttribute($map)) {
                    $style[] = $cssEquivalent . ':' . pq($el)->attr($map);
                }
            }

            if (count($style)) {
                $CssParser = new CssParser('#ruleset {' . implode(';', $style) . '}');
                $CssDocument = $CssParser->parse();
                $ruleset = $CssDocument->getAllRulesets();
                $ruleset = reset($ruleset);
                if (PhpQuery::$enableCssShorthand) {
                    $ruleset->expandShorthands();
                }
                foreach ($ruleset->getRules() as $value) {
                    $rule = $value->getRule();
                    if (!isset($existing[$rule])
                        || 1000 >= $existing[$rule]['specificity']) {
                        $value = $value->getValue();
                        $value = is_object($value) ? (string)$value : $value;
                        $existing[$rule] = [
                            'specificity' => 1000,
                            'value'       => $value,
                        ];
                    }
                }
                PhpQuery::pq($el)->data('phpquery-css', $existing);
                $this->bubbleCSS(PhpQuery::pq($el));
            }
        }
    }

    /**
     * @param $key
     * @param null $value
     *
     * @return self|mixed
     */
    public function data($key, $value = null)
    {
        if (!isset($value)) {
            // is child which we look up doesn't exist
            return $this->get(0)->getAttribute("data-$key");
        }

        /** @var \DOMElement $node */
        foreach ($this as $node) {
            $node->setAttribute("data-$key", $value);
        }

        return $this;
    }

    /**
     * @param PhpQueryObject $element
     *
     * @throws \Exception
     */
    protected function bubbleCSS(PhpQueryObject $element)
    {
        $style = $element->data('phpquery-css');
        foreach ($element->children() as $elementChild) {
            $existing = PhpQuery::pq($elementChild)->data('phpquery-css');
            foreach ($style as $rule => $value) {
                if (!isset($existing[$rule])
                    || $value['specificity'] > $existing[$rule]['specificity']) {
                    $existing[$rule] = $value;
                }
            }
            PhpQuery::pq($elementChild)->data('phpquery-css', $existing);
            if (PhpQuery::pq($elementChild)->children()->size()) {
                $this->bubbleCSS(PhpQuery::pq($elementChild));
            }
        }
    }

    /**
     * @param null $selector
     *
     * @return PhpQueryObject
     *
     * @throws PhpQueryException
     */
    public function children($selector = null) : PhpQueryObject
    {
        $stack = [];
        foreach ($this->stack(1) as $node) {
            foreach ($node->childNodes as $newNode) {
                if ($newNode->nodeType !== 1) {
                    continue;
                }

                if ($selector && !$this->is($selector, $newNode)) {
                    continue;
                }

                if ($this->elementsContainsNode($newNode, $stack)) {
                    continue;
                }

                $stack[] = $newNode;
            }
        }

        $this->elementsBackup = $this->elements;
        $this->elements = $stack;

        return $this->newInstance();
    }

    /**
     * @return self
     *
     * @throws PhpQueryException
     */
    public function show() : self
    {
        $display = $this->data('phpquery-display-state') ?: 'block';
        $this->css('display', $display);

        return $this;
    }

    /**
     * @param $propertyName
     * @param bool $value
     *
     * @return mixed|null|self
     *
     * @throws PhpQueryException
     * @throws \Exception
     */
    public function css($propertyName, $value = null)
    {
        if (!isset($this->cssIsParsed[$this->getDocumentID()])
            || $this->cssIsParsed[$this->getDocumentID()] === false) {

            $this->parseCSS();
        }

        $data = $this->data('phpquery-css');
        if (!$value) {
            if (isset($data[$propertyName])) {
                return $data[$propertyName]['value'];
            }

            return null;
        }

        $specificity = isset($data[$propertyName]) ? $data[$propertyName]['specificity'] + 1 : 1000;
        $data[$propertyName] = [
            'specificity' => $specificity,
            'value'       => $value,
        ];

        $this->data('phpquery-css', $data);
        $this->bubbleCSS(PhpQuery::pq($this->get(0), $this->getDocumentID()));

        if ($specificity >= 1000) {
            $styles = [];
            foreach ($this->data('phpquery-css') as $k => $v) {
                if ($v['specificity'] >= 1000) {
                    $styles[$k] = trim($k) . ':' . trim($v['value']);
                }
            }
            ksort($styles);
            $style = '';
            if (empty($styles)) {
                $this->removeAttr('style');
            } elseif (PhpQuery::$enableCssShorthand) {
                $parser = new Parser('{' . implode(';', $styles) . '}');
                $doc = $parser->parse();
                $doc->createShorthands();
                $style = trim((string)$doc, "\n\r\t {}");
            } else {
                $style = implode(';', $styles);
            }

            $this->attr('style', $style);
        }

        return $this;
    }

    /**
     * @return self
     *
     * @throws PhpQueryException
     */
    public function hide() : self
    {
        $this->data('phpquery-display-state', $this->css('display'));
        $this->css('display', 'none');

        return $this;
    }

    /**
     * @return self
     *
     * @throws PhpQueryException
     */
    public function clone() : self
    {
        $newStack = [];
        $this->elementsBackup = $this->elements;
        foreach ($this->elements as $node) {
            $newStack[] = $node->cloneNode(true);
        }

        $this->elements = $newStack;

        return $this->newInstance();
    }

    /**
     * @param $selector
     *
     * @return PhpQueryObject
     *
     * @throws \Exception
     */
    public function insertBefore($selector) : PhpQueryObject
    {
        return $this->insert($selector, __FUNCTION__);
    }

    /**
     * @param $target
     * @param $type
     *
     * @return self
     *
     * @throws PhpQueryException
     */
    public function insert($target, $type) : self
    {
        $to = false;
        switch ($type) {
            case 'appendTo':
            case 'prependTo':
            case 'insertBefore':
            case 'insertAfter':
                $to = true;
        }

        $insertFrom = $insertTo = [];
        switch (gettype($target)) {
            case 'string':
                if ($to) {
                    // INSERT TO
                    $insertFrom = $this->elements;
                    if (PhpQuery::isMarkup($target)) {
                        // $target is new markup, import it
                        $insertTo = $this->documentWrapper->import($target);
                        // insert into selected element
                    } else {
                        // $tagret is a selector
                        $thisStack = $this->elements;
                        $this->toRoot();
                        $insertTo = $this->find($target)->elements;
                        $this->elements = $thisStack;
                    }
                } else {
                    // INSERT FROM
                    $insertTo = $this->elements;
                    $insertFrom = $this->documentWrapper->import($target);
                }
                break;

            case 'object':
                // PhpQuery
                if ($target instanceof self) {
                    if ($to) {
                        $insertTo = $target->elements;
                        if ($this->documentFragment && $this->stackIsRoot()) {
                            $loop = $this->root->childNodes;
                        } else {
                            $loop = $this->elements;
                        }
                        // import nodes if needed
                        $insertFrom = $this->getDocumentID() === $target->getDocumentID()
                            ? $loop
                            : $target->documentWrapper->import($loop);
                    } else {
                        $insertTo = $this->elements;
                        if ($target->documentFragment && $target->stackIsRoot()) {
                            $loop = $target->root->childNodes;
                        } else {
                            $loop = $target->elements;
                        }
                        // import nodes if needed
                        $insertFrom = $this->getDocumentID() === $target->getDocumentID()
                            ? $loop
                            : $this->documentWrapper->import($loop);
                    }
                    // DOMNode
                } elseif ($target instanceof \DOMNode) {
                    if ($to) {
                        $insertTo = [$target,];
                        if ($this->documentFragment && $this->stackIsRoot()) {
                            // get all body children
                            $loop = $this->root->childNodes;
                        } else {
                            $loop = $this->elements;
                        }

                        foreach ($loop as $fromNode) {
                            // import nodes if needed
                            $insertFrom[] = !$fromNode->ownerDocument->isSameNode($target->ownerDocument)
                                ? $target->ownerDocument->importNode($fromNode, true)
                                : $fromNode;
                        }
                    } else {
                        // import node if needed
                        if (!$target->ownerDocument->isSameNode($this->document)) {
                            $target = $this->document->importNode($target, true);
                        }

                        $insertTo = $this->elements;
                        $insertFrom[] = $target;
                    }
                }
                break;
        }

        $firstChild = $nextSibling = null;
        foreach ($insertTo as $insertNumber => $toNode) {
            // we need static relative elements in some cases
            switch ($type) {
                case 'prependTo':
                case 'prepend':
                    $firstChild = $toNode->firstChild;
                    break;

                case 'insertAfter':
                case 'after':
                    $nextSibling = $toNode->nextSibling;
                    break;
            }

            foreach ($insertFrom as $fromNode) {
                // clone if inserted already before
                $insert = $insertNumber ? $fromNode->cloneNode(true) : $fromNode;
                switch ($type) {
                    case 'appendTo':
                    case 'append':
                        $toNode->appendChild($insert);
                        break;

                    case 'prependTo':
                    case 'prepend':
                        $toNode->insertBefore($insert, $firstChild);
                        break;

                    case 'insertBefore':
                    case 'before':
                        if (!$toNode->parentNode) {
                            throw new PhpQueryException("No parentNode, can't do $type()");
                        }

                        $toNode->parentNode->insertBefore($insert, $toNode);

                        break;

                    case 'insertAfter':
                    case 'after':
                        if (!$toNode->parentNode) {
                            throw new PhpQueryException("No parentNode, can't do $type()");
                        }

                        $toNode->parentNode->insertBefore($insert, $nextSibling);
                        break;
                }
            }
        }

        return $this;
    }

    /**
     * Enter description here...
     * NON JQUERY METHOD
     *
     * Watch out, it doesn't creates new instance, can be reverted with end().
     *
     * @return self
     */
    public function toRoot() : self
    {
        $this->elements = [$this->root,];

        return $this;
    }

    /**
     * @return bool
     */
    protected function stackIsRoot() : bool
    {
        return $this->size() === 1 && $this->isRoot($this->elements[0]);
    }

    /**
     * @param $node
     *
     * @return bool
     */
    protected function isRoot($node) : bool
    {
        return $node instanceof \DOMDocument
            || ($node instanceof \DOMElement && $node->tagName === 'html')
            || $this->root->isSameNode($node);
    }

    /**
     * @param $content
     *
     * @return self
     *
     * @throws PhpQueryException
     */
    public function append($content) : self
    {
        return $this->insert($content, __FUNCTION__);
    }

    /**
     * @return self
     */
    public function end() : self
    {
        return $this->previous ?: $this;
    }

    /**
     * @param int $start
     * @param int|null $end
     *
     * @return self
     *
     * @throws PhpQueryException
     */
    public function slice(int $start, int $end = null) : self
    {
        if ($end > 0) {
            $end -= $start;
        }

        return $this->newInstance(array_slice($this->elements, $start, $end));
    }

    /**
     * @return self
     *
     * @throws PhpQueryException
     */
    public function contents() : self
    {
        $stack = [];
        foreach ($this->stack(1) as $el) {
            foreach ($el->childNodes as $node) {
                $stack[] = $node;
            }
        }

        return $this->newInstance($stack);
    }

    /**
     * @link http://docs.jquery.com/Manipulation/replaceWith#content
     *
     * @param $content
     *
     * @return self
     *
     * @throws PhpQueryException
     */
    public function replaceWith($content) : self
    {
        return $this->after($content)->remove();
    }

    /**
     * @param null $selector
     *
     * @return self
     *
     * @throws PhpQueryException
     */
    public function remove($selector = null) : self
    {
        $loop = $selector ? $this->filter($selector)->elements : $this->elements;
        foreach ($loop as $node) {
            if (!$node->parentNode) {
                continue;
            }

            $node->parentNode->removeChild($node);
        }

        return $this;
    }

    /**
     * @param $content
     *
     * @return self
     *
     * @throws PhpQueryException
     */
    public function after($content) : self
    {
        return $this->insert($content, __FUNCTION__);
    }

    /**
     * @param $selector
     *
     * @return self
     *
     * @throws PhpQueryException
     * @throws \Exception
     */
    public function replaceAll($selector) : self
    {
        foreach (PhpQuery::pq($selector, $this->getDocumentID()) as $node) {
            PhpQuery::pq($node, $this->getDocumentID())->after($this->clone())->remove();
        }

        return $this;
    }

    /**
     * @param null $xml
     *
     * @return bool|PhpQueryObject|string
     *
     * @throws PhpQueryException
     */
    public function xml($xml = null)
    {
        return $this->html($xml);
    }

    /**
     * @return string
     *
     * @throws PhpQueryException
     */
    public function xmlOuter() : string
    {
        return $this->htmlOuter();
    }

    /**
     * @return string
     *
     * @throws PhpQueryException
     */
    public function __toString() : string
    {
        return $this->markupOuter();
    }

    /**
     * @return string
     *
     * @throws PhpQueryException
     */
    public function markupOuter() : string
    {
        if ($this->documentWrapper->isXML) {
            return $this->xmlOuter();
        }

        return $this->htmlOuter();
    }

    /**
     * @param null $selector
     *
     * @return self
     *
     * @throws PhpQueryException
     */
    public function ancestors($selector = null) : self
    {
        return $this->children($selector);
    }

    /**
     * @param $selector
     *
     * @return self
     *
     * @throws PhpQueryException
     */
    public function appendTo($selector) : self
    {
        return $this->insert($selector, __FUNCTION__);
    }

    /**
     * @param $content
     *
     * @return self
     *
     * @throws PhpQueryException
     */
    public function prepend($content) : self
    {
        return $this->insert($content, __FUNCTION__);
    }

    /**
     * @param $selector
     *
     * @return self
     *
     * @throws PhpQueryException
     */
    public function prependTo($selector) : self
    {
        return $this->insert($selector, __FUNCTION__);
    }

    /**
     * @param $content
     *
     * @return self
     *
     * @throws PhpQueryException
     */
    public function before($content) : self
    {
        return $this->insert($content, __FUNCTION__);
    }

    /**
     * @param $seletor
     *
     * @return PhpQueryObject
     *
     * @throws PhpQueryException
     */
    public function insertAfter($seletor) : self
    {
        return $this->insert($seletor, __FUNCTION__);
    }

    /**
     * @param $subject
     *
     * @return int
     *
     * @throws PhpQueryException
     */
    public function index($subject) : int
    {
        $index = -1;
        $subject = $subject instanceof self
            ? $subject->elements[0]
            : $subject;

        foreach ($this->newInstance() as $k => $node) {
            if ($node->isSameNode($subject)) {
                $index = $k;
            }
        }

        return $index;
    }

    /**
     * @return self
     *
     * @throws PhpQueryException
     */
    public function reverse() : self
    {
        $this->elementsBackup = $this->elements;
        $this->elements = array_reverse($this->elements);

        return $this->newInstance();
    }

    // TODO phpdoc; $oldAttr is result of hasAttribute, before any changes

    /**
     * @param null $selector
     *
     * @return self
     *
     * @throws PhpQueryException
     */
    public function prev($selector = null) : self
    {
        return $this->newInstance($this->getElementSiblings('previousSibling', $selector, true));
    }

    /**
     * @param null $selector
     *
     * @return self
     *
     * @throws PhpQueryException
     */
    public function prevAll($selector = null) : self
    {
        return $this->newInstance($this->getElementSiblings('previousSibling', $selector));
    }

    /**
     * @param null $selector
     *
     * @return self
     *
     * @throws PhpQueryException
     */
    public function nextAll($selector = null) : self
    {
        return $this->newInstance($this->getElementSiblings('nextSibling', $selector));
    }

    /**
     * @param null $selector
     *
     * @return PhpQueryObject
     *
     * @throws PhpQueryException
     * @throws \Exception
     */
    public function add($selector = null) : self
    {
        if (!$selector) {
            return $this;
        }

        $this->elementsBackup = $this->elements;
        $found = PhpQuery::pq($selector, $this->getDocumentID());
        $this->merge($found->elements);

        return $this->newInstance();
    }

    protected function merge() : void
    {
        foreach (func_get_args() as $nodes) {
            foreach ($nodes as $newNode) {
                if (!$this->elementsContainsNode($newNode)) {
                    $this->elements[] = $newNode;
                }
            }
        }
    }

    /**
     * @param null $selector
     *
     * @return self
     *
     * @throws PhpQueryException
     */
    public function parent($selector = null) : self
    {
        $stack = [];
        foreach ($this->elements as $node) {
            if ($node->parentNode && !$this->elementsContainsNode($node->parentNode, $stack)) {
                $stack[] = $node->parentNode;
            }
        }

        $this->elementsBackup = $this->elements;
        $this->elements = $stack;
        if ($selector) {
            $this->filter($selector, true);
        }

        return $this->newInstance();
    }

    /**
     * @param null $selector
     *
     * @return self
     *
     * @throws PhpQueryException
     */
    public function parents($selector = null) : self
    {
        $stack = [];
        foreach ($this->elements as $node) {
            $test = $node;
            while ($test->parentNode) {
                $test = $test->parentNode;
                if ($this->isRoot($test)) {
                    break;
                }

                if (!$this->elementsContainsNode($test, $stack)) {
                    $stack[] = $test;
                    continue;
                }
            }
        }
        $this->elementsBackup = $this->elements;
        $this->elements = $stack;
        if ($selector) {
            $this->filter($selector, true);
        }

        return $this->newInstance();
    }

    /**
     * @param string $className
     *
     * @return bool
     *
     * @throws PhpQueryException
     */
    public function hasClass(string $className) : bool
    {
        foreach ($this->stack(1) as $node) {
            if ($this->is(".$className", $node)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $className
     *
     * @return self
     *
     * @throws PhpQueryException
     */
    public function toggleClass(string $className) : self
    {
        foreach ($this->stack(1) as $node) {
            if ($this->is($node, '.' . $className)) {
                $this->removeClass($className);
            } else {
                $this->addClass($className);
            }
        }

        return $this;
    }

    /**
     * @param string $className
     *
     * @return PhpQueryObject
     */
    public function removeClass(string $className) : self
    {
        foreach ($this->stack(1) as $node) {
            $classes = explode(' ', $node->getAttribute('class'));
            if (\in_array($className, $classes, true)) {
                $classes = array_diff($classes, [$className,]);
                if ($classes) {
                    $node->setAttribute('class', implode(' ', $classes));
                } else {
                    $node->removeAttribute('class');
                }
            }
        }

        return $this;
    }

    /**
     * @param string $className
     *
     * @return PhpQueryObject
     *
     * @throws PhpQueryException
     */
    public function addClass(string $className) : self
    {
        if (!$className) {
            return $this;
        }

        foreach ($this->stack(1) as $node) {
            if (!$this->is(".$className", $node)) {
                $node->setAttribute('class', trim($node->getAttribute('class') . ' ' . $className));
            }
        }

        return $this;
    }

    /**
     * Proper name without underscore (just ->empty()) also works.
     *
     * Removes all child nodes from the set of matched elements.
     *
     * Example:
     * pq("p")._empty()
     *
     * HTML:
     * <p>Hello, <span>Person</span> <a href="#">and person</a></p>
     *
     * Result:
     * [ <p></p> ]
     *
     * @return self
     */
    public function empty() : self
    {
        foreach ($this->stack(1) as $node) {
            $node->nodeValue = '';
        }

        return $this;
    }

    /**
     * @return int
     */
    public function count() : int
    {
        return $this->size();
    }

    /**
     * @param callable $callback
     *
     * @return self
     */
    public function each(Callable $callback) : self
    {
        foreach ($this->elements as $v) {
            $callback($v);
        }

        return $this;
    }

    /**
     * @param $key
     *
     * @return self
     */
    public function removeData(string $key) : self
    {
        /** @var \DOMElement $node */
        foreach ($this as $node) {
            $node->removeAttribute("data-$key");
        }

        return $this;
    }

    public function rewind() : void
    {
        $this->elementsBackup = $this->elements;
        $this->elementsIterator = $this->elements;
        $this->valid = isset($this->elements[0]) ? 1 : 0;
        $this->current = 0;
    }
    // INTERFACE IMPLEMENTATIONS

    // ITERATOR INTERFACE

    /**
     * @return \DOMElement
     */
    public function current() : \DOMElement
    {
        return $this->elementsIterator[$this->current];
    }

    /**
     * @access private
     */
    public function key() : int
    {
        return $this->current;
    }

    /**
     * Double-function method.
     *
     * First: main iterator interface method.
     * Second: Returning next sibling, alias for _next().
     *
     * Proper functionality is choosed automatically.
     *
     * @param string|null $selector
     *
     * @return PhpQueryObject
     *
     * @throws PhpQueryException
     */
    public function next(string $selector = null) : self
    {
        $this->valid = isset($this->elementsIterator[$this->current + 1]) ? true : false;
        if (!$this->valid && $this->elementsIterator) {
            $this->elementsIterator = null;
        } else if ($this->valid) {
            $this->current++;
        }

        return $this->_next($selector);
    }

    /**
     * Safe rename of next().
     *
     * Use it ONLY when need to call next() on an iterated object (in same time).
     * Normally there is no need to do such thing ;)
     *
     * @param null $selector
     *
     * @return self
     *
     * @throws PhpQueryException
     */
    public function _next($selector = null) : self
    {
        return $this->newInstance($this->getElementSiblings('nextSibling', $selector, true));
    }

    /**
     * @return bool
     */
    public function valid() : bool
    {
        return $this->valid;
    }

    // ITERATOR INTERFACE END
    // ARRAYACCESS INTERFACE

    /**
     * @param mixed $offset
     *
     * @return bool
     *
     * @throws PhpQueryException
     */
    public function offsetExists($offset) : bool
    {
        return $this->find($offset)->size() > 0;
    }

    /**
     * @param mixed $offset
     *
     * @return self
     *
     * @throws PhpQueryException
     */
    public function offsetGet($offset) : self
    {
        return $this->find($offset);
    }

    /**
     * @param mixed $offset
     * @param mixed $value
     *
     * @throws PhpQueryException
     */
    public function offsetSet($offset, $value) : void
    {
        $this->find($offset)->html($value);
    }

    /**
     * @param mixed $offset
     *
     * @throws PhpQueryException
     */
    public function offsetUnset($offset) : void
    {
        throw new PhpQueryException("Can't do unset, use array interface only for calling queries and replacing HTML.");
    }

    /**
     * @return bool|string
     *
     * @throws PhpQueryException
     */
    public function htmlOuter()
    {
        return $this->documentWrapper->markup($this->elements);
    }
}

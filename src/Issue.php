<?php

namespace Tuchsoft\IssueReporter;

use DateTime;
use Exception;

class Issue implements \JsonSerializable
{

    public const SEVERITY_ERROR = 5;
    public const SEVERITY_WARNING = 3;
    public const SEVERITY_TIP = 0;
    public const SEVERITY_ERROR_STRING = 'ERROR';
    public const SEVERITY_WARNING_STRING = 'WARNING';
    public const SEVERITY_TIP_STRING = 'TIP';
    public const SEVERITY_DEFAULT = self::SEVERITY_WARNING;
    public const UNKNOW_CODE = 'unknown';
    /**
     * @var string The issue code.
     */
    private string $code;

    /**
     * @var int One of AbstractCheck::SEVERITY_* constants.
     */
    private int $severity;

    /**
     * @var string The issue message.
     */
    private string $message;

    /**
     * @var string The file path where the issue was found (relative to plugin root).
     */
    private string $path;

    /**
     * @var string The file path where the issue was found (relative to plugin root).
     */
    private string $relativePath = '';
    /**
     * @var int The line number where the issue was found.
     */
    private int $line = 0;

    /**
     * @var int The column number where the issue was found.
     */
    private int $column = 0;

    /**
     * @var string An optional reference for the issue.
     */
    private string $ref;

    /**
     * @var string An optional help message for the issue.
     */
    private string $help;

    /**
     * @var array Additional data for the issue.
     */
    private array $extra = [];

    /**
     * @var int Unix timestamp in millisecond when the Issue was first found (or reported)
     */
    private int $time;
    

    /**
     * Issue constructor.
     *
     * @param string $code The issue code.
     * @param int $severity One of AbstractCheck::SEVERITY_* constants.
     * @param string $message The issue message.
     * @param string|null $path The file path where the issue was found (relative to plugin root).
     * @param int|null $line The line number where the issue was found.
     */
    public function __construct(
        string $code,
        int $severity,
        string $message,
        ?string $path = null,
        ?int $line = 0,
        ?int $col = 0,
        ?string $ref = '',
        ?string $help = '',
        ?int $time = null,
    )
    {
        $this->code = $code;
        $this->severity = $severity;
        $this->message = $message;
        $this->path = $path ?? '.';
        $this->line = $line ?? 0;
        $this->column = $col ?? 0;
        $this->ref = $ref ?? '';
        $this->help = $help ?? '';
        $this->time = $time ?? 0;
    }

    /**
     * Creates an Issue object from a parsed array.
     *
     * This method iterates through the provided array and assigns values
     * to properties only if the property exists in the class.
     *
     * @param array $data The associative array to create the object from.
     * @return Issue
     * @throws Exception If a required key is missing from the array.
     */
    public static function fromJson(array $data): Issue
    {
        // First, check for required keys to ensure the object can be created.
        $requiredKeys = ['code', 'severity', 'message', 'path', 'line'];
        foreach ($requiredKeys as $key) {
            if (!isset($data[$key])) {
                throw new Exception("Missing required key: '$key' in array data.");
            }
        }

        // Use the constructor for initial creation with required properties.
        $issue = new self(
            $data['code'],
            $data['severity'],
            $data['message'],
            $data['path'],
            $data['line']
        );

        // Now, loop through the remaining data and assign to existing properties.
        foreach ($data as $key => $value) {
            if ($value !== null && property_exists($issue, $key)) {
                // Use a dedicated setter if one exists, otherwise set directly.
                $setterMethod = 'set' . ucfirst($key);
                if (method_exists($issue, $setterMethod)) {
                    // Call the setter to use its validation logic.
                    $issue->$setterMethod($value);
                }
            }
        }

        return $issue;
    }


    /**
     * Get the issue code.
     *
     * @return string
     */
    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * Set the issue code.
     *
     * @param string $code
     * @return self
    
     */
    public function setCode(string $code): self
    {
       
        $this->code = $code;
        return $this;
    }

    /**
     * Get the issue severity.
     *
     * @return int
     */
    public function getSeverity(): int
    {
        return $this->severity;
    }


    public function getSeverityString(): string
    {
        return match ($this->severity) {
            static::SEVERITY_ERROR => static::SEVERITY_ERROR_STRING,
            static::SEVERITY_WARNING => static::SEVERITY_WARNING_STRING,
            static::SEVERITY_TIP => static::SEVERITY_TIP_STRING
        };
    }

    /**
     * Set the issue severity.
     *
     * @param int $severity
     * @return self
    
     */
    public function setSeverity(int $severity): self
    {
       
        $this->severity = $severity;
        return $this;
    }

    /**
     * Get the issue message.
     *
     * @return string
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * Set the issue message.
     *
     * @param string $message
     * @return self
    
     */
    public function setMessage(string $message): self
    {
       
        $this->message = $message;
        return $this;
    }

    /**
     * Get the file path where the issue was found.
     *
     * @return string
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Set the file path where the issue was found.
     *
     * @param string $path
     * @return self
    
     */
    public function setPath(string $path): self
    {
       
        $this->path = $path;
        return $this;
    }

    /**
     * Get the line number where the issue was found.
     *
     * @return int
     */
    public function getLine(): int
    {
        return $this->line;
    }

    /**
     * Set the line number where the issue was found.
     *
     * @param int $line
     * @return self
    
     */
    public function setLine(int $line): self
    {
       
        $this->line = $line;
        return $this;
    }

    /**
     * Get the issue reference.
     *
     * @return string
     */
    public function getRef(): string
    {
        return $this->ref;
    }

    /**
     * Set the issue reference.
     *
     * @param string $ref
     * @return self
    
     */
    public function setRef(string $ref): self
    {
       
        $this->ref = $ref;
        return $this;
    }

    /**
     * Get the issue help message.
     *
     * @return string
     */
    public function getHelp(): string
    {
        return $this->help;
    }

    /**
     * Set the issue help message.
     *
     * @param string $help
     * @return self
    
     */
    public function setHelp(string $help): self
    {
       
        $this->help = $help;
        return $this;
    }

    /**
     * Get the message data.
     *
     * @return array
     */
    public function getextra(): array
    {
        return $this->extra;
    }

    /**
     * Set the message data.
     *
     * @param array $extra
     * @return self
    
     */
    public function setextra(array $extra): self
    {
       
        $this->extra = $extra;
        return $this;
    }

    /**
     * Add data to the message data array.
     *
     * @param string $key
     * @param int|string|bool|float $value
     * @return self
    
     */
    public function addextra(string $key, int|string|bool|float $value): self
    {
       
        $this->extra[$key] = $value;
        return $this;
    }


    /**
     * Add a prefix to the issue code.
     *
     * @param string $codePrefix
     * @return self
    
     */
    public function addCode(string $codePrefix): self
    {
       
        $this->code = $this->code ? "$codePrefix.$this->code" : $codePrefix;
        return $this;
    }


    /**
     * Add data to the message
     *
     * @param string $key The key of the data to be replaced in the message
     * @param string $value The value of the data to be replaced in the message
     * @return self
    
     */
    public function addMessage(string $key, string $value): self
    {
       
        $this->extra[$key] = $value;
        return $this;
    }

    public function getColumn(): int
    {
        return $this->column;
    }

    public function setColumn(int $column): void
    {
        $this->column = $column;
    }





    /**
     * Specify data which should be serialized to JSON.
     * @return array
     */
    public function jsonSerialize(): array
    {
        // Return an array of all properties that should be serialized.
        return [
            'code' => $this->code,
            'severity' => $this->severity,
            'message' => $this->message,
            'path' => $this->path,
            'relativePath' => $this->relativePath,
            'line' => $this->line,
            'column' => $this->column,
            'ref' => $this->ref,
            'help' => $this->help,
            'extra' => $this->extra,
        ];
    }

    /**
     * Return the relative path form the report basePath
     * with './' prefix, and '/' if directory
     * @return string relative path form the report basePath
     */
    public function getRelativePath(): string
    {
        return $this->relativePath;
    }

    public function setRelativePath(string $relativePath): void
    {
        $this->relativePath = $relativePath;
    }

    public function getTime(): int
    {
        return $this->time;
    }

    public function setTime(int $time): void
    {
        $this->time = $time;
    }





}
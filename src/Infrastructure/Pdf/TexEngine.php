<?php
declare(strict_types=1);

namespace App\Infrastructure\Pdf;

use App\Infrastructure\Pdf\Document\PdfDocumentInterface;

use RuntimeException;

/**
 * LaTeX Engine
 */
class TexEngine
{
    /**
     * Path to the tex binary of your choice.
     *
     * @var string
     */
    protected $binary = '/usr/bin/latexpdf';

    /**
     * @var array
     */
    protected $config = [];

    /**
     * Constructor
     *
     * @param array $config Config Options
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge_recursive($this->config, $config);
        if (!isset($this->config['options']['output-directory'])) {
            $this->config['options']['output-directory'] = TMP . 'pdf';
        }
    }

    /**
     * Write the tex file.
     *
     * @return string Returns the file name of the written tex file.
     */
    protected function writeTexFile()
    {
        $output = $this->Pdf->html();
        $file = sha1($output);
        $texFile = $this->config['options.output-directory'] . DS . $file;
        file_put_contents($texFile, $output);

        return $texFile;
    }

    /**
     * Clean up the files generated by tex.
     *
     * @param  string $texFile Tex file name.
     * @return void
     */
    protected function cleanUpTexFiles($texFile)
    {
        $extensions = ['aux', 'log', 'pdf'];
        foreach ($extensions as $extension) {
            $texFile .= '.' . $extension;
            if (file_exists($texFile)) {
                unlink($texFile);
            }
        }
    }

    /**
     * Generates Pdf from html
     *
     * @throws \Cake\Core\Exception\Exception
     * @return string raw pdf data
     */
    public function output()
    {
        $texFile = $this->writeTexFile();
        $content = $this->exec($this->getCommand(), $texFile);

        if (strpos(mb_strtolower($content['stderr']), 'error')) {
            throw new RuntimeException("System error <pre>" . $content['stderr'] . "</pre>");
        }

        if (mb_strlen($content['stdout'], $this->Pdf->encoding()) === 0) {
            throw new RuntimeException("TeX compiler binary didn't return any data");
        }

        if ((int)$content['return'] !== 0 && !empty($content['stderr'])) {
            throw new RuntimeException("Shell error, return code: " . (int)$content['return']);
        }

        $result = file_get_contents($texFile . '.pdf');
        $this->cleanUpTexFiles($texFile);

        return $result;
    }

    /**
     * Execute the latex binary commands for rendering pdfs
     *
     * @param  string $cmd   the command to execute
     * @param  string $input Html to pass to wkhtmltopdf
     * @return array the result of running the command to generate the pdf
     */
    protected function exec($cmd, $input)
    {
        $cmd .= ' ' . $input;

        $result = ['stdout' => '', 'stderr' => '', 'return' => ''];

        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], $input);
        fclose($pipes[0]);

        $result['stdout'] = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $result['stderr'] = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $result['return'] = proc_close($proc);

        return $result;
    }

    /**
     * Builds the command.
     *
     * @return string The command with params and options.
     */
    protected function buildCommand()
    {
        $command = $this->binary;
        $options = (array)$this->config['options'];

        foreach ($options as $key => $value) {
            if (empty($value)) {
                continue;
            } elseif ($value === true) {
                $command .= ' --' . $key;
            } else {
                $command .= sprintf(' --%s %s', $key, escapeshellarg($value));
            }
        }

        return $command;
    }

    /**
     * Get the command to render a pdf
     *
     * @return string the command for generating the pdf
     * @throws \Cake\Core\Exception\Exception
     */
    protected function getCommand()
    {
        $binary = $this->config['binary'];

        if ($binary) {
            $this->binary = $binary;
        }
        if (!is_executable($this->binary)) {
            throw new RuntimeException(
                sprintf(
                    'TeX compiler binary is not found or not executable: %s',
                    $this->binary
                )
            );
        }

        $options = (array)$this->config['options'];

        if (!is_dir($options['output-directory'])) {
            if (!mkdir($concurrentDirectory = $options['output-directory']) && !is_dir($concurrentDirectory)) {
                throw new RuntimeException(sprintf(
                    'Directory `%s` was not created',
                    $concurrentDirectory
                ));
            }
        }

        return $this->buildCommand();
    }
}

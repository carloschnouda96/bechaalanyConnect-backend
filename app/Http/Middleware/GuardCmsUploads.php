<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Keeps active content out of CMS uploads.
 *
 * Several vendor upload routes store a file on the public disk with no type check,
 * and a stored HTML or SVG page runs as script on the CMS origin when opened. So every
 * file on an admin request is judged here, by its name and by what it really contains,
 * whichever vendor action it is heading for.
 */
class GuardCmsUploads
{
    private const REFUSED_EXTENSIONS = [
        'html', 'htm', 'xhtml', 'shtml', 'svg', 'svgz', 'xml', 'xsl', 'js', 'mjs', 'swf',
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pl', 'py', 'cgi', 'sh', 'htaccess',
    ];

    private const REFUSED_MIME_FRAGMENTS = ['html', 'svg', 'xml', 'javascript', 'php', 'x-shellscript'];

    public function handle(Request $request, Closure $next)
    {
        if (!$request->is(config('hellotree.cms_route_prefix') . '/*')) {
            return $next($request);
        }

        // The vendor echoes this into an inline script.
        if ($request->has('CKEditorFuncNum') && !ctype_digit((string) $request->input('CKEditorFuncNum'))) {
            return response('Invalid request.', 422);
        }

        foreach ($this->files($request->allFiles()) as $file) {
            if ($this->isActiveContent($file)) {
                return response('This file type cannot be uploaded.', 422);
            }
        }

        return $next($request);
    }

    /** @return iterable<UploadedFile> */
    private function files(array $files): iterable
    {
        foreach ($files as $file) {
            if (is_array($file)) {
                yield from $this->files($file);
            } elseif ($file instanceof UploadedFile) {
                yield $file;
            }
        }
    }

    private function isActiveContent(UploadedFile $file): bool
    {
        if (in_array(strtolower($file->getClientOriginalExtension()), self::REFUSED_EXTENSIONS, true)) {
            return true;
        }

        $path = $file->getRealPath();
        if (!$path || !is_readable($path)) {
            return false;
        }

        // The real content, not the type the client or a fake upload declares.
        $mime = strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->file($path));
        foreach (self::REFUSED_MIME_FRAGMENTS as $fragment) {
            if (str_contains($mime, $fragment)) {
                return true;
            }
        }

        // A browser sniffs markup that finfo calls plain text.
        $head = ltrim((string) file_get_contents($path, false, null, 0, 512), "\xEF\xBB\xBF \t\r\n");

        return str_starts_with($head, '<');
    }
}

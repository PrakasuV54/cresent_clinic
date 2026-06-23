<?php
/**
 * Simple Template Parser to handle Jinja2-style tags in PHP
 * Supports: {{ var }}, {{ url_for('static', filename='path') }}, {% if var %}...{% endif %}
 */

class TemplateParser {
    public static function render($templatePath, $data = []) {
        if (!file_exists($templatePath)) {
            return "Template not found: $templatePath";
        }

        $content = file_get_contents($templatePath);

        // 1. Handle {% if var %} ... {% endif %}
        // This is a simple regex-based parser. For nested ifs or complex logic, it would need more.
        // But for our project, simple checks are enough.
        $content = preg_replace_callback('/\{% if (.*?) %\}(.*?)\{% endif %\}/s', function($matches) use ($data) {
            $varName = trim($matches[1]);
            $innerContent = $matches[2];
            if (isset($data[$varName]) && $data[$varName]) {
                return $innerContent;
            }
            return '';
        }, $content);

        // 2. Handle {{ url_for('static', filename='...') }}
        $content = preg_replace_callback('/\{\{ url_for\(\'static\', filename=\'(.*?)\'\) \}\}/', function($matches) {
            return '/static/' . $matches[1];
        }, $content);

        // 3. Handle {{{ var }}} for raw HTML (no escaping)
        $content = preg_replace_callback('/\{\{\{ (.*?) \}\}\}/', function($matches) use ($data) {
            $key = trim($matches[1]);
            return isset($data[$key]) ? $data[$key] : '';
        }, $content);

        // 4. Handle {{ var }} for escaped HTML
        $content = preg_replace_callback('/\{\{ (.*?) \}\}/', function($matches) use ($data) {
            $key = trim($matches[1]);
            return isset($data[$key]) ? htmlspecialchars($data[$key]) : '';
        }, $content);

        return $content;
    }
}

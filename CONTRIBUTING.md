# Contributing to PressViber

Thank you for your interest in contributing! This document covers how to report bugs, propose features, and submit code.

---

## Before You Start

- Search [existing issues](../../issues) before opening a new one.
- For large changes (new tools, architecture changes), open a discussion or issue first so we can align before you invest time writing code.
- All contributions are subject to the [GPL v2 license](LICENSE).

---

## Development Setup

### Requirements
- PHP 8.0+
- WordPress 6.0+ (local install — [LocalWP](https://localwp.com), [Lando](https://lando.dev), or Docker)
- A [DeepSeek API key](https://platform.deepseek.com) for testing the agent

### Steps

```bash
# 1. Fork and clone into your WP plugins directory
cd wp-content/plugins
git clone https://github.com/YOUR_FORK/pressviber ai-vibe-builder

# 2. Activate in WordPress admin → Plugins
# 3. Enter your DeepSeek API key in the plugin settings
# 4. Open AI Builder in the admin sidebar
```

There is no build step — the plugin is pure PHP + vanilla JS + CSS.

---

## Adding a New Agent Tool

1. **Define the tool** in `includes/class-agent.php` inside `get_tool_definitions()`:

```php
[
    'name'        => 'my_new_tool',
    'description' => 'What this tool does, when to call it.',
    'parameters'  => [
        'type'       => 'object',
        'properties' => [
            'param_one' => [ 'type' => 'string', 'description' => 'Description.' ],
        ],
        'required'   => [ 'param_one' ],
    ],
],
```

2. **Implement the handler** in `dispatch_tool()` inside `class-agent.php`:

```php
case 'my_new_tool':
    $result = $this->my_new_tool_handler( $args['param_one'] ?? '' );
    break;
```

3. **Implement the logic** either inline or in one of the supporting classes (`class-file-manager.php`, `class-site-inspector.php`, `class-command-runner.php`).

4. **Test it** by asking the agent to use it from the chat interface.

---

## Code Style

- Follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/) for PHP.
- Use tabs for indentation in PHP; spaces in JS/CSS.
- Keep methods focused — one responsibility per method.
- No external PHP dependencies (no Composer packages). Keep it self-contained.
- All user-facing strings must be wrapped in `esc_html_e()` or `esc_html__()` for i18n.

---

## Submitting a Pull Request

1. Create a branch: `git checkout -b feature/my-feature` or `fix/bug-description`
2. Make your changes with clear, atomic commits
3. Test manually: open the chat, run a task that exercises your change, verify the result
4. Push and open a PR against the `main` branch
5. Fill in the PR template completely

PRs that include only documentation changes, typo fixes, or formatting are also welcome.

---

## Reporting Bugs

Open an issue using the **Bug Report** template. Include:
- WordPress version and PHP version
- The exact prompt you typed
- What the agent did (which tools it called)
- What you expected vs. what happened
- Any PHP error log output (check `wp-content/debug.log` with `WP_DEBUG_LOG` enabled)

---

## Security Issues

Please do **not** open public issues for security vulnerabilities. Email `security@falt.ai` directly with a description and proof of concept. We will respond within 48 hours.

---

## Code of Conduct

Be respectful. Contributions are reviewed by humans. Constructive criticism is welcome; hostility is not.

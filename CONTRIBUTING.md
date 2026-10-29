# Contributing to AI-Function-Caller-PHP

Thank you for your interest in contributing to **AI-Function-Caller-PHP**! We welcome bug reports, feature suggestions, and pull requests.

## Development Setup

1. Clone the repository:
   ```bash
   git clone https://github.com/Anton-Barinov/AI-Function-Caller-PHP.git
   cd AI-Function-Caller-PHP
   ```

2. AI-Function-Caller-PHP is zero-dependency by default and has a built-in PSR-4 autoloader (`src/autoload.php`).

3. Run the test suite:
   ```bash
   php tests/run_tests.php
   ```

4. Run the CLI tool:
   ```bash
   bin/ai-caller --version
   ```

## Code Quality Standards

- PHP 8.1+ strictly typed (`declare(strict_types=1);` in every file).
- Clear, descriptive error messages and exception handling.
- Keep the core library lightweight and free of mandatory external dependencies.
- Ensure all tests pass with zero warnings or notices before submitting pull requests.

## Pull Request Guidelines

1. Create a feature branch: `git checkout -b feature/my-new-feature`.
2. Commit your changes with meaningful commit messages.
3. Add corresponding test cases in `tests/`.
4. Open a pull request against the `main` branch.

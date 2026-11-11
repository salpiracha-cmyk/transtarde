from playwright.sync_api import sync_playwright
with sync_playwright() as runtime:
    browser=runtime.chromium.launch(headless=True)
    try:
        page=browser.new_page()
        page.set_content('<!doctype html><p>Browser rendering ready</p>')
        assert page.locator('p').text_content()=='Browser rendering ready'
        assert len(page.screenshot())>0
        print('BROWSER_TEST_RUNTIME_READY')
    finally:
        browser.close()

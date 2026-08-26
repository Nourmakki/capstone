import { useEffect } from "react";
import { useLocation } from "react-router-dom";

/**
 * Puts every new page at the top.
 *
 * A single page app never reloads, so the browser keeps whatever scroll
 * offset the previous page had. Opening a roadmap from halfway down the
 * dashboard would otherwise drop you into the middle of it.
 *
 * Keyed on pathname only: changing the query string - picking an older
 * roadmap version, say - is not a new page and should not move the reader.
 */
function ScrollToTop() {
    const { pathname } = useLocation();

    useEffect(() => {
        window.scrollTo(0, 0);
    }, [pathname]);

    return null;
}

export default ScrollToTop;

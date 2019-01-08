/* Mahdi Agnelli { Add download button to videojs player */
require(['jquery'], function($) {
    setTimeout(function () {
        var videojsCollection = document.getElementsByClassName("video-js");
        var videojsPlayerID;
        for (i = 0; i < videojsCollection.length; i++) {
            videojsPlayerID = videojsCollection[i].id + '_html5_api'
            var player = videojs(videojsPlayerID);
            player.downloadButton();
        }
    }, 2000);
});
;
/* } Mahdi Agnelli */
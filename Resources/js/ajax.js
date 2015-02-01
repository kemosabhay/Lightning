lightning.ajax = {
    /**
     * This will hold the original jQuery ajax.
     */
    jqueryAjax: function(){},

    /**
     * Called at startup to replace the original jQuery function with the lightning version.
     */
    init: function() {
        // Save the original ajax function.
        this.jqueryAjax = jQuery.ajax;

        // Override the jquery ajax function.
        jQuery.ajax = this.call;
    },

    /**
     * The lightning callback that will wrap the user callback.
     *
     * @param {string|object} data
     *   The data returned by the server.
     * @param {function} success_callback
     *   The user success callback, if any.
     * @param {function} error_callback
     *   The user error callback, if any.
     */
    success: function(data, success_callback, error_callback) {
        // If the output was HTML, add it to the dialog.
        if(settings.dataType == "HTML") {
            if(success_callback){
                success_callback(data);
            } else {
                lightning.dialog.setContent(data);
            }
        }
        // Add standard messages to the dialog.
        if(data.messages){
            for(var i in data.messages) {
                lightning.dialog.add(data.messages[i], 'message');
            }
        }
        if(data.status == 'success'){
            if(success_callback){
                success_callback(data);
            } else {
                lightning.dialog.hide();
            }
        } else if(data.status == 'redirect') {
            // TODO: check for redirect cookie
            document.location = data.location;
        } else {
            // TODO: make this more graceful.
            lightning.ajax.error(data);
            if(error_callback) {
                error_callback(data);
            }
        }
    },

    /**
     * The lightning ajax error handler.
     *
     * @param {string|object} data
     *   The response from the server.
     */
    error: function(data) {
        lightning.dialog.showPrepared(function(){
            if(typeof(data)=='string'){
                lightning.dialog.add(data, 'error');
            } else if(data.errors){
                for(var i in data.errors) {
                    lightning.dialog.add(data.errors[i], 'error');
                }
            } else {
                lightning.dialog.addError('There was an error loading the page. Please reload the page. If the problem persists, please <a href="/contact">contact support</a>.');
                if(data.hasOwnProperty('status')) {
                    lightning.dialog.add('HTTP: ' + data.status, 'error');
                }
                if(data.hasOwnProperty('responseText') && !data.responseText.match(/<html/i)) {
                    lightning.dialog.add(data.responseText, 'error');
                }
            }
        });
    },

    /**
     * This will replace the jQuery.ajax method, and will wrap the user success and error
     * callbacks with the lightning standard callbacks.
     *
     * @param {object} settings
     *   The settings intended for the jQuery.ajax call.
     */
    call: function(settings) {
        var self = this;
        // Override the success handler.
        settings.success = function(data){
            self.success(data, settings.success, settings.error);
        };
        // Override the error handler.
        settings.error = function (data){
            // TODO: make this more graceful.
            self.error(data);
            // Allows an additional error handler.
            if(settings.error) {
                settings.error(data);
            }
        };
        // Call the original ajax function.
        this.jqueryAjax(settings);
    }
};